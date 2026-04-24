<?php

declare(strict_types=1);

namespace Tests\Unit\Services\PaymentAddresses;

use App\Contracts\DerivationIndexStoreInterface;
use App\Contracts\EvmAddressDeriverInterface;
use App\Data\InvoiceAddressContext;
use App\Services\PaymentAddresses\Evm\DerivedAddressResult;
use App\Services\PaymentAddresses\EvmPaymentAddressAllocator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\BuildsDomainData;
use Tests\TestCase;

final class EvmPaymentAddressAllocatorTest extends TestCase
{
    use BuildsDomainData;
    use RefreshDatabase;

    public function test_it_marks_local_hd_addresses_as_hd_derived(): void
    {
        config()->set('payment_addresses.evm.default_key_refs.evm_local', 'anvil:default');

        $allocator = new EvmPaymentAddressAllocator(
            new class implements DerivationIndexStoreInterface {
                public function reserveNext(string $family, string $networkKey, string $keyRef): int
                {
                    return 7;
                }
            },
            new class implements EvmAddressDeriverInterface {
                public function derive(string $networkKey, string $keyRef, int $index, string $pathTemplate): DerivedAddressResult
                {
                    return new DerivedAddressResult(
                        address: '0x1234567890123456789012345678901234567890',
                        derivationPath: "m/44'/60'/1234'/0/7",
                        derivationIndex: $index,
                        keyRef: $keyRef,
                        meta: [
                            'source' => 'local_hd_mnemonic',
                            'temporary' => true,
                            'local_testing_only' => true,
                        ]
                    );
                }
            }
        );

        $merchant = $this->createMerchant();

        $paymentAddress = $allocator->allocate(
            $merchant,
            'eth_local',
            'evm_local',
            new InvoiceAddressContext('inv_local_hd', 'ext_local_hd')
        );

        self::assertSame('hd_derived', $paymentAddress->strategy);
        self::assertSame('local_hd_mnemonic', $paymentAddress->meta['source'] ?? null);
    }

    public function test_it_marks_dev_rpc_accounts_with_dev_strategy(): void
    {
        config()->set('payment_addresses.evm.default_key_refs.evm_local', 'anvil:default');

        $allocator = new EvmPaymentAddressAllocator(
            new class implements DerivationIndexStoreInterface {
                public function reserveNext(string $family, string $networkKey, string $keyRef): int
                {
                    return 1;
                }
            },
            new class implements EvmAddressDeriverInterface {
                public function derive(string $networkKey, string $keyRef, int $index, string $pathTemplate): DerivedAddressResult
                {
                    return new DerivedAddressResult(
                        address: '0xf39fd6e51aad88f6f4ce6ab8827279cfffb92266',
                        derivationPath: null,
                        derivationIndex: $index,
                        keyRef: $keyRef,
                        meta: [
                            'source' => 'rpc_accounts_dev',
                            'temporary' => true,
                        ]
                    );
                }
            }
        );

        $merchant = $this->createMerchant();

        $paymentAddress = $allocator->allocate(
            $merchant,
            'eth_local',
            'evm_local',
            new InvoiceAddressContext('inv_rpc_dev', 'ext_rpc_dev')
        );

        self::assertSame('rpc_accounts_dev', $paymentAddress->strategy);
        self::assertSame('rpc_accounts_dev', $paymentAddress->meta['source'] ?? null);
    }
}
