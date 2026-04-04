<?php
declare(strict_types=1);

namespace App\Services\PaymentAddresses\Evm;

use App\Contracts\EvmAddressDeriverInterface;
use App\Services\Evm\EvmRpcClient;
use App\Support\Chains\ChainRegistry;
use RuntimeException;


/**
 * Temporary dev-only address origin for local/testing EVM networks
 *
 * It's not production custody-model.
 * Product deriver must be replaced with HD/custody implementation,
 * which will be able to work through key_ref without linear dependency from eth_accounts.
 */
class DevRpcAccountAddressDeriver implements EvmAddressDeriverInterface
{
    public function __construct(
        private readonly ChainRegistry $chains,
    )
    {
    }

    public function derive(string $networkKey, string $keyRef, int $index, string $pathTemplate): DerivedAddressResult
    {
        if (!app()->environment(['local', 'testing'])) {
            throw new RuntimeException(
                'DevRpcAccountAddressDeriver is only available in local/testing environments.'
            );
        }

        $chain = $this->chains->get($networkKey);
        $rpcUrl = (string) ($chain['rpc_url'] ?? '');

        if ($rpcUrl === '') {
            throw new RuntimeException("Missing rpc_url for EVM network [$networkKey]");
        }

        $client = new EvmRpcClient($rpcUrl);
        $accounts = $client->call('eth_accounts');

        if (!is_array($accounts) || $accounts === []) {
            throw new RuntimeException("EVM network [$networkKey] returned no RPC accounts");
        }

        if (!array_key_exists($index, $accounts)) {
            throw new RuntimeException(
                "Dev RPC account source for [{$networkKey}] is exhausted at index [{$index}]. " .
                'This adapter is temporary. Switch to a real custody/HD deriver.'
            );
        }

        $address = strtolower((string) $accounts[$index]);

        return new DerivedAddressResult(
            address: $address,
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
