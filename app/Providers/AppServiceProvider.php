<?php

namespace App\Providers;

use App\Contracts\DerivationIndexStoreInterface;
use App\Contracts\EvmAddressDeriverInterface;
use App\Contracts\EvmGasTopUpServiceInterface;
use App\Contracts\EvmInvoiceMonitorInterface;
use App\Contracts\EvmPayoutSenderInterface;
use App\Contracts\EvmSweepSourceResolverInterface;
use App\Contracts\EvmTokenPayoutSenderInterface;
use App\Contracts\EvmTransactionSignerInterface;
use App\Services\Evm\EvmErc20PayoutSender;
use App\Services\Evm\EvmGasTopUpService;
use App\Services\Evm\EvmInvoiceMonitor;
use App\Services\Evm\EvmNativePayoutSender;
use App\Services\Evm\EvmSweepSourceResolver;
use App\Services\Evm\Signers\DevRpcAccountEvmTransactionSigner;
use App\Services\PaymentAddresses\Evm\DatabaseDerivationIndexStore;
use App\Services\PaymentAddresses\Evm\DevRpcAccountAddressDeriver;
use App\Services\PaymentAddresses\Evm\LocalHdMnemonicEvmDeriver;
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

        $this->app->bind(
            EvmSweepSourceResolverInterface::class,
            EvmSweepSourceResolver::class
        );

        $this->app->bind(
            EvmPayoutSenderInterface::class,
            EvmNativePayoutSender::class
        );

        $this->app->bind(EvmTransactionSignerInterface::class, function ($app) {
            return $app->make(DevRpcAccountEvmTransactionSigner::class);
        });

        $this->app->bind(
            EvmTokenPayoutSenderInterface::class,
            EvmErc20PayoutSender::class
        );

        $this->app->bind(
            EvmGasTopUpServiceInterface::class,
            EvmGasTopUpService::class
        );

        $this->app->bind(EvmAddressDeriverInterface::class, function ($app) {
            $configuredDeriver = config('payment_addresses.evm.deriver');

            if (is_string($configuredDeriver) && $configuredDeriver !== '') {
                return $app->make($configuredDeriver);
            }

            $isLocalOrTesting = $app->environment(['local', 'testing']);
            $localHdEnabled = (bool) config('payment_addresses.evm.local_hd_enabled', false);

            if ($localHdEnabled && !$isLocalOrTesting) {
                throw new RuntimeException(
                    'payment_addresses.evm.local_hd_enabled may only be used in local/testing environments.'
                );
            }

            if ($isLocalOrTesting && $localHdEnabled) {
                return $app->make(LocalHdMnemonicEvmDeriver::class);
            }

            if ((bool)config('payment_addresses.evm.allow_dev_rpc_accounts', false) === true) {
                return $app->make(DevRpcAccountAddressDeriver::class);
            }

            throw new RuntimeException(
                'No EVM address deriver is configured. ' .
                'Configure payment_addresses.evm.deriver or bind EvmAddressDeriverInterface to a real custody/HD implementation.'
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
