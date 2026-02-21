<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('ledger_entries', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('wallet_id');
            $t->string('type', 32); // deposit_pending, deposit_confirm, withdraw_hold, withdraw_final, hold_release
            $t->string('bucket_from', 16)->nullable(); // available|pending_in|held|null
            $t->string('bucket_to', 16)->nullable();
            $t->bigInteger('amount'); // >0, в минимальных единицах
            $t->string('idempotency_key', 128)->nullable();
            $t->json('meta')->nullable();
            $t->timestamps();

            $t->index(['wallet_id', 'created_at']);
            $t->unique(['idempotency_key'], 'uq_ledger_idempotency');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ledger_entries');
    }
};
