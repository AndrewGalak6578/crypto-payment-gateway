<?php

namespace App\Providers;

use App\Contracts\DerivationIndexStoreInterface;
use App\Contracts\EvmAddressDeriverInterface;
use App\Contracts\EvmInvoiceMonitorInterface;
use App\Services\Evm\EvmInvoiceMonitor;
use App\Services\PaymentAddresses\Evm\DatabaseDerivationIndexStore;
use App\Services\PaymentAddresses\Evm\DevRpcAccountAddressDeriver;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(
            DerivationIndexStoreInterface::class,
            DatabaseDerivationIndexStore::class
        );
        $this->app->bind(
            EvmInvoiceMonitorInterface::class,
            EvmInvoiceMonitor::class
        );

        $this->app->bind(EvmAddressDeriverInterface::class, function ($app) {
            if ((bool)config('payment_addresses.evm.allow_dev_rpc_accounts', false) === true) {
                return $app->make(DevRpcAccountAddressDeriver::class);
            }

            throw new RuntimeException(
                'No EVM address deriver is configured. ' .
                'Bind EvmAddressDeriverInterface to a real custody/HD implementation.'
            );
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
