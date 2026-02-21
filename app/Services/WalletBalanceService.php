<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * WalletBalanceService
 *
 * Consistency model:
 * ------------------
 * Баланс пользователя реализован по модели EVENT SOURCING LIGHT:
 * - ledger_entries является источником истины (source of truth)
 * - wallet_balances — агрегированный snapshot для быстрых проверок.
 *
 * Все изменения баланса происходят только через append-only ledger,
 * после чего обновляется агрегированное состояние.
 *
 * Данный сервис НЕ выполняет сетевые операции.
 * Все blockchain взаимодействия происходят асинхронно через jobs.
 */
class WalletBalanceService
{
    private const DAILY_WITHDRAW_LIMIT = 1_000_000_00; // пример: 1,000,000.00 в "центах токена"

    /**
     * Регистрирует входящий депозит в статусе pending.
     *
     * Используется при обнаружении транзакции в блокчейне,
     * когда она ещё не получила достаточное количество подтверждений.
     *
     * Деньги НЕ становятся доступными пользователю и не могут быть списаны.
     *
     * Инварианты:
     * - операция атомарна (DB transaction + row lock)
     * - баланс изменяется только через ledger
     * - защищено от повторной обработки через idempotency_key
     *
     * Риски, которые покрывает:
     * - duplicate webhook / повторный парсинг блока
     * - race condition при параллельной обработке депозитов
     *
     * @throws RuntimeException
     */
    public function depositPending(int $walletId, int $amount, string $idemKey): void
    {
        $this->assertPositive($amount);
        DB::transaction(function () use ($walletId, $amount, $idemKey) {
            $this->lockOrCreateBalance($walletId);

            $this->insertLedger($walletId, 'deposit_pending', null, 'pending_in', $amount, $idemKey);

            DB::table('wallet_balances')->where('wallet_id', $walletId)->update([
                'pending_in' => DB::raw("pending_in + {$amount}"),
                'updated_at' => now(),
            ]);
        });
    }

    /**
     * Подтверждает депозит после достижения необходимого числа confirmations.
     *
     * Перемещает средства:
     * pending_in → available
     *
     * После выполнения средства становятся доступными для вывода.
     *
     * Инварианты:
     * - нельзя подтвердить больше, чем находится в pending_in
     * - операция выполняется внутри транзакции
     * - создаётся audit trail в ledger
     *
     * Риски:
     * - предотвращает двойное подтверждение одной транзакции
     * - защищает от рассинхронизации indexer'а блокчейна
     *
     * @throws RuntimeException
     */
    public function confirmDeposit(int $walletId, int $amount, string $idemKey): void
    {
        $this->assertPositive($amount);
        DB::transaction(function () use ($walletId, $amount, $idemKey) {
            $bal = $this->lockOrCreateBalance($walletId);

            if ((int)$bal->pending_in < $amount) {
                throw new RuntimeException('Not enough pending_in to confirm');
            }

            $this->insertLedger($walletId, 'deposit_confirm', 'pending_in', 'available', $amount, $idemKey);

            DB::table('wallet_balances')->where('wallet_id', $walletId)->update([
                'pending_in' => DB::raw("pending_in - {$amount}"),
                'available'  => DB::raw("available + {$amount}"),
                'updated_at' => now(),
            ]);
        });
    }

    /**
     * Создаёт холд средств перед выводом или платежом.
     *
     * Средства временно блокируются:
     * available → held
     *
     * Фактическое списание происходит позже,
     * после успешной отправки транзакции в блокчейн.
     *
     * Инварианты:
     * - проверяется достаточность available баланса
     * - применяется риск-контроль (лимиты / velocity checks)
     * - операция идемпотентна
     *
     * Назначение холда:
     * - защита от double spend
     * - асинхронная отправка blockchain tx
     * - возможность rollback при ошибке сети
     *
     * @return string hold identifier
     *
     * @throws RuntimeException
     */
    public function withdrawHold(int $walletId, int $amount, string $idemKey, array $riskMeta = []): string
    {
        $this->assertPositive($amount);

        return DB::transaction(function () use ($walletId, $amount, $idemKey, $riskMeta) {
            $bal = $this->lockOrCreateBalance($walletId);

            // Минимальные риски: лимит + наличие средств
            $this->assertDailyLimitNotExceeded($walletId, $amount);

            if ((int)$bal->available < $amount) {
                throw new RuntimeException('Insufficient funds');
            }

            $holdId = (string) Str::uuid();

            $this->insertLedger($walletId, 'withdraw_hold', 'available', 'held', $amount, $idemKey, [
                'hold_id' => $holdId,
                'risk' => $riskMeta,
            ]);

            DB::table('wallet_balances')->where('wallet_id', $walletId)->update([
                'available' => DB::raw("available - {$amount}"),
                'held'      => DB::raw("held + {$amount}"),
                'updated_at' => now(),
            ]);

            return $holdId;
        });
    }

    /**
     * Финализирует вывод средств после успешного подтверждения транзакции.
     *
     * Средства окончательно списываются:
     * held → spent (удаляются из пользовательского баланса)
     *
     * Вызывается асинхронным воркером после подтверждения tx в сети.
     *
     * Инварианты:
     * - невозможно списать больше, чем находится в held
     * - операция необратима (финальная стадия)
     * - ledger хранит audit операции
     *
     * Риски:
     * - предотвращает повторное финализирование вывода
     * - защищает баланс при повторных callback'ах ноды
     *
     * @throws RuntimeException
     */
    public function finalizeWithdraw(int $walletId, int $amount, string $idemKey, string $holdId): void
    {
        $this->assertPositive($amount);

        DB::transaction(function () use ($walletId, $amount, $idemKey, $holdId) {
            $bal = $this->lockOrCreateBalance($walletId);

            if ((int)$bal->held < $amount) {
                throw new RuntimeException('Not enough held to finalize');
            }

            $this->insertLedger($walletId, 'withdraw_final', 'held', null, $amount, $idemKey, [
                'hold_id' => $holdId,
            ]);

            DB::table('wallet_balances')->where('wallet_id', $walletId)->update([
                'held' => DB::raw("held - {$amount}"),
                'updated_at' => now(),
            ]);
        });
    }

    /**
     * Отменяет вывод и возвращает средства пользователю.
     *
     * Используется если:
     * - транзакция не отправлена
     * - blockchain RPC вернул ошибку
     * - риск-система отклонила вывод
     *
     * Перемещение средств:
     * held → available
     *
     * Инварианты:
     * - возможен только при наличии средств в held
     * - операция полностью атомарна
     *
     * Риски:
     * - предотвращает потерю средств при failed withdraw
     * - позволяет безопасные retry операции
     *
     * @throws RuntimeException
     */
    public function releaseHold(int $walletId, int $amount, string $idemKey, string $holdId, string $reason): void
    {
        $this->assertPositive($amount);

        DB::transaction(function () use ($walletId, $amount, $idemKey, $holdId, $reason) {
            $bal = $this->lockOrCreateBalance($walletId);

            if ((int)$bal->held < $amount) {
                throw new RuntimeException('Not enough held to release');
            }

            $this->insertLedger($walletId, 'hold_release', 'held', 'available', $amount, $idemKey, [
                'hold_id' => $holdId,
                'reason' => $reason,
            ]);

            DB::table('wallet_balances')->where('wallet_id', $walletId)->update([
                'held'      => DB::raw("held - {$amount}"),
                'available' => DB::raw("available + {$amount}"),
                'updated_at' => now(),
            ]);
        });
    }

    /**
     * Получает строку агрегированного баланса с блокировкой FOR UPDATE.
     *
     * Гарантирует отсутствие race condition при параллельных изменениях.
     * Если баланс отсутствует — создаёт его.
     *
     * Используется всеми денежными операциями сервиса.
     */
    private function lockOrCreateBalance(int $walletId): object
    {
        $row = DB::table('wallet_balances')->where('wallet_id', $walletId)->lockForUpdate()->first();
        if ($row) {
            return $row;
        }

        DB::table('wallet_balances')->insert([
            'wallet_id' => $walletId,
            'available' => 0,
            'pending_in' => 0,
            'held' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return DB::table('wallet_balances')->where('wallet_id', $walletId)->lockForUpdate()->first();
    }

    /**
     * Создаёт запись в журнале операций (ledger).
     *
     * Ledger является единственным источником правды
     * для финансовых операций и используется для аудита
     * и восстановления состояния баланса.
     *
     * Idempotency обеспечивается уникальным ключом операции.
     */
    private function insertLedger(
        int $walletId,
        string $type,
        ?string $from,
        ?string $to,
        int $amount,
        string $idemKey,
        array $meta = []
    ): void {
        // Если idemKey уже был — unique constraint кинет исключение.
        DB::table('ledger_entries')->insert([
            'wallet_id' => $walletId,
            'type' => $type,
            'bucket_from' => $from,
            'bucket_to' => $to,
            'amount' => $amount,
            'idempotency_key' => $idemKey,
            'meta' => $meta ? json_encode($meta) : null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Валидирует, что сумма операции положительная.
     *
     * Защищает от некорректных входных данных
     * и логических ошибок вызывающего кода.
     *
     * @throws RuntimeException
     */
    private function assertPositive(int $amount): void
    {
        if ($amount <= 0) {
            throw new RuntimeException('Amount must be positive');
        }
    }

    /**
     * Проверяет превышение дневного лимита вывода.
     *
     * Простая risk-control защита от:
     * - компрометации аккаунта
     * - автоматических массовых выводов
     *
     * Лимит считается по операциям withdraw_hold за текущие сутки.
     *
     * @throws RuntimeException
     */
    private function assertDailyLimitNotExceeded(int $walletId, int $amount): void
    {
        $today = now()->startOfDay();

        $sum = (int) DB::table('ledger_entries')
            ->where('wallet_id', $walletId)
            ->where('type', 'withdraw_hold')
            ->where('created_at', '>=', $today)
            ->sum('amount');

        if ($sum + $amount > self::DAILY_WITHDRAW_LIMIT) {
            throw new RuntimeException('Daily withdraw limit exceeded');
        }
    }
}