<?php
declare(strict_types=1);

namespace App\Services\Evm\Signers;

use App\Contracts\EvmTransactionSignerInterface;
use App\Data\EvmSweepSource;
use App\Services\Evm\EvmRpcClient;
use App\Support\Chains\ChainRegistry;
use RuntimeException;

final readonly class DevRpcAccountEvmTransactionSigner implements EvmTransactionSignerInterface
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

        $chain = $this->chains->get($networkKey);
        $rpcUrl = (string) ($chain['rpc_url'] ?? '');

        if ($rpcUrl === '') {
            throw new RuntimeException("Missing rpc_url for EVM network [{$networkKey}]");
        }

        $client = new EvmRpcClient($rpcUrl);

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

        $txHash = (string)$client->call('eth_sendTransaction', [$payload]);

        return [
            'raw_tx' => '',
            'tx_hash' => $txHash,
            'meta' => [
                'signer' => 'dev_rpc_account',
                'temporary' => true,
                'submitted_via' =>'eth_sendTransaction',
            ]
        ];
    }
}
