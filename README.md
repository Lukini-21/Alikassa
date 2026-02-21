# Crypto Wallet Balance Module (Laravel 12)

Минимальный модуль учёта крипто-баланса пользователя с учётом:
- асинхронности блокчейна (pending → confirmed),
- безопасного списания через hold,
- идемпотентности (защита от дублей),
- атомарности (транзакции + блокировки).

## Основная идея

Баланс пользователя хранится в двух представлениях:
- **ledger_entries** — журнал операций (источник истины для аудита),
- **wallet_balances** — агрегированный snapshot для быстрых проверок.

Состояния средств:
- `pending_in` — депозит обнаружен, но ещё не подтверждён,
- `available` — подтверждённые средства, доступные к списанию,
- `held` — временно заблокировано под вывод/платёж.

## Почему так сделано

Блокчейн:
- события приходят асинхронно,
- возможны повторы (webhook retries),
- подтверждения появляются позже,
- выводы должны быть безопасны от double spend.

Поэтому:
- депозит сначала идёт в `pending_in`, затем подтверждается и попадает в `available`,
- списание делается в два этапа: `hold` → `finalize` или `release`.

## Гарантии

- **Atomicity**: все операции проходят в `DB::transaction()` и используют `SELECT ... FOR UPDATE`.
- **Idempotency**: повторный запрос с тем же `idempotency_key` не создаст двойной эффект.
- **Race safety**: блокировка строки `wallet_balances` защищает от параллельных списаний.
- **Auditability**: каждая операция фиксируется в `ledger_entries`.

## Схема таблиц

- `wallets` — кошелёк пользователя по активу.
- `wallet_balances` — агрегат баланса (`available`, `pending_in`, `held`).
- `ledger_entries` — журнал изменений (append-only), содержит `idempotency_key`.

## Установка

1) Установить зависимости
```bash
composer install
```
2) Миграции
```bash
   php artisan migrate
```
## Использование (сервис)

App\Services\WalletBalanceService содержит операции:

1) Deposit detected (pending)

Начисление обнаруженного депозита в pending_in (ещё не доступно для вывода).

`$service->depositPending($walletId, $amount, $idemKey);
2) Deposit confirmed

Подтверждение депозита, перенос pending_in → available.

`$service->confirmDeposit($walletId, $amount, $idemKey);
3) Hold for withdraw

Блокировка средств для вывода: available → held.

`$holdId = $service->withdrawHold($walletId, $amount, $idemKey, $riskMeta);
4) Finalize withdraw

Финальное списание после успешной on-chain операции: held → spent.

`$service->finalizeWithdraw($walletId, $amount, $idemKey, $holdId);
5) Release hold

Отмена вывода и возврат средств: held → available.

`$service->releaseHold($walletId, $amount, $idemKey, $holdId, $reason);