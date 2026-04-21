<?php
declare(strict_types=1);

namespace Tests\Unit\Providers;

use App\Contracts\EvmAddressDeriverInterface;
use App\Services\PaymentAddresses\Evm\DevRpcAccountAddressDeriver;
use App\Services\PaymentAddresses\Evm\LocalHdMnemonicEvmDeriver;
use RuntimeException;
use Tests\TestCase;

final class AppServiceProviderEvmDeriverSelectionTest extends TestCase
{
    public function test_configured_deriver_has_highest_priority(): void
    {
        config()->set('payment_addresses.evm.deriver', DevRpcAccountAddressDeriver::class);
        config()->set('payment_addresses.evm.local_hd_enabled', true);
        config()->set('payment_addresses.evm.allow_dev_rpc_accounts', true);

        $resolved = $this->app->make(EvmAddressDeriverInterface::class);

        self::assertInstanceOf(DevRpcAccountAddressDeriver::class, $resolved);
    }

    public function test_local_hd_is_selected_before_dev_rpc_fallback_in_testing(): void
    {
        config()->set('payment_addresses.evm.deriver', null);
        config()->set('payment_addresses.evm.local_hd_enabled', true);
        config()->set('payment_addresses.evm.allow_dev_rpc_accounts', true);

        $resolved = $this->app->make(EvmAddressDeriverInterface::class);

        self::assertInstanceOf(LocalHdMnemonicEvmDeriver::class, $resolved);
    }

    public function test_dev_rpc_fallback_is_used_when_local_hd_is_disabled(): void
    {
        config()->set('payment_addresses.evm.deriver', null);
        config()->set('payment_addresses.evm.local_hd_enabled', false);
        config()->set('payment_addresses.evm.allow_dev_rpc_accounts', true);

        $resolved = $this->app->make(EvmAddressDeriverInterface::class);

        self::assertInstanceOf(DevRpcAccountAddressDeriver::class, $resolved);
    }

    public function test_local_hd_flag_is_rejected_outside_local_testing(): void
    {
        config()->set('payment_addresses.evm.deriver', null);
        config()->set('payment_addresses.evm.local_hd_enabled', true);
        config()->set('payment_addresses.evm.allow_dev_rpc_accounts', true);

        $this->app['env'] = 'production';
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('local_hd_enabled may only be used in local/testing');

        try {
            $this->app->make(EvmAddressDeriverInterface::class);
        } finally {
            $this->app['env'] = 'testing';
        }
    }
}
