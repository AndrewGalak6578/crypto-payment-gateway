<?php
declare(strict_types=1);

namespace Tests\Unit\Services\Evm\Signers;

use App\Data\EvmSweepSource;
use App\Services\Evm\Signers\DevRpcAccountEvmTransactionSigner;
use App\Support\Chains\ChainRegistry;
use RuntimeException;
use Tests\TestCase;

final class DevRpcAccountEvmTransactionSignerTest extends TestCase
{
    public function test_it_uses_impersonation_fallback_for_local_hd_sources(): void
    {
        config()->set('payment_addresses.evm.local_hd_enabled', true);

        $rpcSpy = new class {
            public bool $firstSendAttempt = true;
            /** @var array<int, array{method: string, params: array}> */
            public array $calls = [];

            public function call(string $method, array $params = []): mixed
            {
                $this->calls[] = ['method' => $method, 'params' => $params];

                if ($method === 'eth_sendTransaction' && $this->firstSendAttempt) {
                    $this->firstSendAttempt = false;
                    throw new RuntimeException('sender account not recognized');
                }

                if ($method === 'eth_sendTransaction') {
                    return '0xaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
                }

                return true;
            }
        };

        $signer = new class(new ChainRegistry(), $rpcSpy) extends DevRpcAccountEvmTransactionSigner {
            public function __construct(ChainRegistry $chains, private readonly object $client)
            {
                parent::__construct($chains);
            }

            protected function makeRpcClient(string $networkKey): object
            {
                return $this->client;
            }
        };

        $source = new EvmSweepSource(
            networkKey: 'evm_local',
            address: '0x1234567890123456789012345678901234567890',
            keyRef: 'anvil:default',
            derivationPath: "m/44'/60'/1234'/0/7",
            derivationIndex: 7,
            strategy: 'hd_derived',
        );

        $signed = $signer->signTransaction('evm_local', $source, [
            'to' => '0x9999999999999999999999999999999999999999',
            'value' => '0x0',
        ]);

        self::assertSame(
            'anvil_impersonateAccount+eth_sendTransaction',
            $signed['meta']['submitted_via'] ?? null
        );
        self::assertSame(
            '0xaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
            $signed['tx_hash'] ?? null
        );

        $methods = array_map(static fn (array $call): string => $call['method'], $rpcSpy->calls);
        self::assertSame([
            'eth_sendTransaction',
            'anvil_impersonateAccount',
            'eth_sendTransaction',
            'anvil_stopImpersonatingAccount',
        ], $methods);
    }
}
