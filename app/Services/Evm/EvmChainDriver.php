<?php
declare(strict_types=1);

namespace App\Services\Evm;

use App\Services\CoinBasedLogic\CoinRpc;
use App\Support\Chains\ChainRegistry;
use BadMethodCallException;
use RuntimeException;

class EvmChainDriver implements CoinRpc
{
    private EvmRpcClient $rpc;

    public function __construct(
        private readonly ChainRegistry $chains,
        private readonly string $networkKey = 'evm_local'
    )
    {
        $chain = $this->chains->get($this->networkKey);

        if (($chain['family'] ?? null) !== 'evm' || ($chain['driver'] ?? null) !== 'evm') {
            throw new RuntimeException("Network [$this->networkKey] is not an EVM chain");
        }

        $rpcUrl = (string) ($chain['rpc_url'] ?? '');

        if ($rpcUrl === '') {
            throw new RuntimeException("Missing rpc_url for EVM network [$this->networkKey]");
        }

        $this->rpc = new EvmRpcClient($rpcUrl);
    }

    public function networkKey(): string
    {
        return $this->networkKey;
    }

    public function clientVersion(): string
    {
        return $this->rpc->clientVersion();
    }

    public function chainId(): int
    {
        return $this->rpc->chainId();
    }

    public function blockNumber(): int
    {
        return $this->rpc->blockNumber();
    }

    public function getNativeBalanceWei(string $address): string
    {
        return $this->rpc->getBalanceWei($address);
    }

    public function getTransactionReceipt(string $txHash): ?array
    {
        return $this->rpc->getTransactionReceipt($txHash);
    }

    public function getNewAddress(string $label = ''): string
    {
        throw new BadMethodCallException(
            'EVM getNewAddress() is not implemented yet. Next step is deposit address strategy.'
        );
    }

    public function getReceivedTotals(string $address, int $confirmedMinConf): array
    {
        throw new BadMethodCallException(
            'EVM getReceivedTotals() is not implemented yet. Next step is EVM invoice monitoring.'
        );
    }

    public function getTransactionsByAddress(string $address, int $minConf = 1, int $count = 1000, ?string $label = null): array
    {
        throw new BadMethodCallException(
            'EVM getTransactionsByAddress() is not implemented yet. Next step is EVM invoice monitoring.'
        );
    }

    public function sendToAddress(string $address, float $amount, ?float $feeRate = null): string
    {
        throw new BadMethodCallException(
            'EVM sendToAddress() is not implemented yet. Next step is EVM settlement flow.'
        );
    }

    public function getBalance(): float
    {
        throw new BadMethodCallException(
            'EVM getBalance() without explicit wallet context is not supported.'
        );
    }
}
