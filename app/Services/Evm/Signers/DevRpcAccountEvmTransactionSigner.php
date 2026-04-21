<?php
declare(strict_types=1);

namespace App\Services\Evm\Signers;

use App\Contracts\EvmTransactionSignerInterface;
use App\Data\EvmSweepSource;
use App\Services\Evm\EvmRpcClient;
use App\Support\Chains\ChainRegistry;
use RuntimeException;
use Throwable;

readonly class DevRpcAccountEvmTransactionSigner implements EvmTransactionSignerInterface
{

    public function __construct(
        private ChainRegistry $chains,
    )
    {
    }

    /**
     * @inheritDoc
     */
    public function signTransaction(string $networkKey, EvmSweepSource $source, array $transaction): array
    {
        if (!app()->environment(['local', 'testing'])) {
            throw new RuntimeException(
                'DevRpcAccountEvmTransactionSigner requires local/testing mode.'
            );
        }

        $client = $this->makeRpcClient($networkKey);

        $payload = [
            'from' => strtolower($source->address),
            'to' => (string) ($transaction['to'] ?? ''),
            'value' => (string) ($transaction['value'] ?? '0x0'),
        ];

        if (isset($transaction['data'])) {
            $payload['data'] = (string) $transaction['data'];
        }

        if (isset($transaction['gas'])) {
            $payload['gas'] = (string) $transaction['gas'];
        }

        if (isset($transaction['gasPrice'])) {
            $payload['gasPrice'] = (string) $transaction['gasPrice'];
        }

        if (isset($transaction['nonce'])) {
            $payload['nonce'] = (string) $transaction['nonce'];
        }

        $submitMethod = 'eth_sendTransaction';
        $impersonated = false;

        try {
            $txHash = (string)$client->call('eth_sendTransaction', [$payload]);
        } catch (Throwable $firstError) {
            if (!$this->shouldUseImpersonationFallback($source)) {
                throw $firstError;
            }

            $client->call('anvil_impersonateAccount', [$payload['from']]);
            $impersonated = true;
            $submitMethod = 'anvil_impersonateAccount+eth_sendTransaction';
            $txHash = (string)$client->call('eth_sendTransaction', [$payload]);
        } finally {
            if ($impersonated) {
                try {
                    $client->call('anvil_stopImpersonatingAccount', [$payload['from']]);
                } catch (Throwable) {
                    // no-op: tx has been submitted already
                }
            }
        }

        return [
            'raw_tx' => '',
            'tx_hash' => $txHash,
            'meta' => [
                'signer' => 'dev_rpc_account',
                'temporary' => true,
                'submitted_via' => $submitMethod,
            ]
        ];
    }

    protected function makeRpcClient(string $networkKey): object
    {
        $chain = $this->chains->get($networkKey);
        $rpcUrl = (string) ($chain['rpc_url'] ?? '');

        if ($rpcUrl === '') {
            throw new RuntimeException("Missing rpc_url for EVM network [{$networkKey}]");
        }

        return new EvmRpcClient($rpcUrl);
    }

    private function shouldUseImpersonationFallback(EvmSweepSource $source): bool
    {
        return (bool) config('payment_addresses.evm.local_hd_enabled', false)
            && $source->strategy === 'hd_derived';
    }
}
