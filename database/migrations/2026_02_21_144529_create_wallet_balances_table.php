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
        Schema::create('wallet_balances', function (Blueprint $t) {
            $t->unsignedBigInteger('wallet_id')->primary();
            $t->bigInteger('available')->default(0);   // подтверждено
            $t->bigInteger('pending_in')->default(0);  // ожидает подтверждений
            $t->bigInteger('held')->default(0);        // в холде на вывод
            $t->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('wallet_balances');
    }
};
