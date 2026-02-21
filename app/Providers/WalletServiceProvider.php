<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Services\WalletBalanceService;

class WalletServiceProvider extends ServiceProvider
{
    public function provides(): array
    {
        return [
            WalletBalanceService::class,
        ];
    }

    public function register(): void
    {
        $this->app->singleton(
            WalletBalanceService::class,
            fn () => new WalletBalanceService()
        );
    }
}