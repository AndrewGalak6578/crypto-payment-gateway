<?php
declare(strict_types=1);

namespace Tests\Unit\Services\PaymentAddresses\Evm;

use App\Services\PaymentAddresses\Evm\LocalHdMnemonicEvmDeriver;
use App\Support\Chains\ChainRegistry;
use RuntimeException;
use Tests\TestCase;

final class LocalHdMnemonicEvmDeriverTest extends TestCase
{
    public function test_different_key_ref_with_separate_config_produces_different_address_for_same_index_slot(): void
    {
        config()->set('payment_addresses.evm.local_hd_enabled', true);
        config()->set('payment_addresses.evm.local_hd_key_refs', [
            'anvil:merchant-a' => [
                'mnemonic' => 'test test test test test test test test test test test junk',
                'passphrase' => '',
                'path_template' => "m/44'/60'/1001'/0/%d",
            ],
            'anvil:merchant-b' => [
                'mnemonic' => 'abandon abandon abandon abandon abandon abandon abandon abandon abandon abandon abandon about',
                'passphrase' => '',
                'path_template' => "m/44'/60'/1001'/0/%d",
            ],
        ]);
        config()->set('chains.evm_local', ['rpc_url' => 'http://unused']);

        $deriver = $this->newDeriverWithFakeKeccak();

        $first = $deriver->derive('evm_local', 'anvil:merchant-a', 7, "m/44'/60'/0'/0/%d");
        $second = $deriver->derive('evm_local', 'anvil:merchant-b', 7, "m/44'/60'/0'/0/%d");

        self::assertNotSame($first->address, $second->address);
        self::assertSame('per_key_ref', $first->meta['root_scope'] ?? null);
        self::assertSame('per_key_ref', $second->meta['root_scope'] ?? null);
    }

    public function test_same_key_ref_with_same_config_is_deterministic(): void
    {
        config()->set('payment_addresses.evm.local_hd_enabled', true);
        config()->set('payment_addresses.evm.local_hd_key_refs', [
            'anvil:merchant-a' => [
                'mnemonic' => 'test test test test test test test test test test test junk',
                'passphrase' => '',
                'path_template' => "m/44'/60'/1001'/0/%d",
            ],
        ]);
        config()->set('chains.evm_local', ['rpc_url' => 'http://unused']);

        $deriver = $this->newDeriverWithFakeKeccak();

        $first = $deriver->derive('evm_local', 'anvil:merchant-a', 7, "m/44'/60'/0'/0/%d");
        $second = $deriver->derive('evm_local', 'anvil:merchant-a', 7, "m/44'/60'/0'/0/%d");

        self::assertSame($first->address, $second->address);
        self::assertSame("m/44'/60'/1001'/0/7", $first->derivationPath);
    }

    public function test_single_root_fallback_is_explicitly_marked_in_meta(): void
    {
        config()->set('payment_addresses.evm.local_hd_mnemonic', 'test test test test test test test test test test test junk');
        config()->set('payment_addresses.evm.local_hd_passphrase', '');
        config()->set('payment_addresses.evm.local_hd_path_template', "m/44'/60'/1234'/0/%d");
        config()->set('payment_addresses.evm.local_hd_enabled', true);
        config()->set('payment_addresses.evm.local_hd_key_refs', []);
        config()->set('chains.evm_local', ['rpc_url' => 'http://unused']);

        $deriver = $this->newDeriverWithFakeKeccak();

        $result = $deriver->derive('evm_local', 'anvil:default', 7, "m/44'/60'/0'/0/%d");
        self::assertSame('single_root_fallback', $result->meta['root_scope'] ?? null);
        self::assertTrue((bool) ($result->meta['single_root_fallback'] ?? false));
        self::assertNotEmpty($result->meta['root_scope_warning'] ?? '');
    }

    public function test_it_rejects_usage_outside_local_testing(): void
    {
        config()->set('payment_addresses.evm.local_hd_mnemonic', 'test test test test test test test test test test test junk');
        config()->set('payment_addresses.evm.local_hd_path_template', "m/44'/60'/1234'/0/%d");
        config()->set('payment_addresses.evm.local_hd_enabled', true);
        config()->set('chains.evm_local', ['rpc_url' => 'http://unused']);
        $this->app['env'] = 'production';

        $deriver = new class(new ChainRegistry()) extends LocalHdMnemonicEvmDeriver {
            protected function makeRpcClient(string $networkKey): object
            {
                throw new RuntimeException('RPC should not be called in this test.');
            }
        };

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('only available in local/testing');

        try {
            $deriver->derive('evm_local', 'anvil:default', 0, "m/44'/60'/0'/0/%d");
        } finally {
            $this->app['env'] = 'testing';
        }
    }

    public function test_it_fails_when_no_per_key_config_and_no_single_root_mnemonic(): void
    {
        config()->set('payment_addresses.evm.local_hd_mnemonic', '');
        config()->set('payment_addresses.evm.local_hd_enabled', true);
        config()->set('payment_addresses.evm.local_hd_key_refs', []);
        config()->set('payment_addresses.evm.local_hd_path_template', "m/44'/60'/1234'/0/%d");
        config()->set('chains.evm_local', ['rpc_url' => 'http://unused']);

        $deriver = new class(new ChainRegistry()) extends LocalHdMnemonicEvmDeriver {
            protected function makeRpcClient(string $networkKey): object
            {
                throw new RuntimeException('RPC should not be called in this test.');
            }
        };

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No per-key local HD root configured');

        $deriver->derive('evm_local', 'anvil:default', 0, "m/44'/60'/0'/0/%d");
    }

    private function newDeriverWithFakeKeccak(): LocalHdMnemonicEvmDeriver
    {
        return new class(new ChainRegistry()) extends LocalHdMnemonicEvmDeriver {
            protected function makeRpcClient(string $networkKey): object
            {
                return new class {
                    public function call(string $method, array $params = []): mixed
                    {
                        if ($method !== 'web3_sha3') {
                            throw new RuntimeException("Unexpected RPC method [{$method}]");
                        }

                        $hex = strtolower((string) ($params[0] ?? ''));
                        $raw = str_starts_with($hex, '0x') ? substr($hex, 2) : $hex;
                        $bytes = hex2bin($raw);
                        if ($bytes === false) {
                            throw new RuntimeException('Invalid hex input for fake web3_sha3.');
                        }

                        return '0x' . hash('sha256', $bytes);
                    }
                };
            }
        };
    }
}
