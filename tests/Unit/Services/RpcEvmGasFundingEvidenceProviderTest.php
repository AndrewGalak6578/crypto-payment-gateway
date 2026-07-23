<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Data\EvmGasFundingReconciliationResult;
use App\Models\EvmGasFunding;
use App\Services\Evm\EvmRpcClient;
use App\Services\Evm\EvmRpcClientFactory;
use App\Services\Evm\RpcEvmGasFundingEvidenceProvider;
use App\Support\Chains\ChainRegistry;
use Tests\TestCase;

final class RpcEvmGasFundingEvidenceProviderTest extends TestCase
{
    public function test_known_transaction_requires_exact_identity_and_confirmed_successful_receipt(): void
    {
        config()->set('chains.evm_local.rpc_url', 'http://evm.test');
        config()->set('chains.evm_local.chain_id', 31337);
        $rpc = new FakeGasFundingRpcClient;
        $provider = new RpcEvmGasFundingEvidenceProvider(
            app(ChainRegistry::class),
            new FakeEvidenceRpcClientFactory($rpc),
        );
        $funding = $this->funding();

        $result = $provider->inspect($funding);

        self::assertSame(EvmGasFundingReconciliationResult::CONFIRMED, $result->outcome);
        self::assertSame($rpc->txHash, $result->txHash);
        self::assertSame(3, $result->confirmations);

        $rpc->transaction['value'] = '0x2';
        $mismatch = $provider->inspect($funding);

        self::assertSame(EvmGasFundingReconciliationResult::INCONCLUSIVE, $mismatch->outcome);
        self::assertSame('evm_gas_funding_target_or_value_mismatch', $mismatch->reason);
    }

    public function test_failed_receipt_is_retry_safe_only_after_required_confirmations(): void
    {
        config()->set('chains.evm_local.rpc_url', 'http://evm.test');
        $rpc = new FakeGasFundingRpcClient;
        $rpc->receipt['status'] = '0x0';
        $provider = new RpcEvmGasFundingEvidenceProvider(
            app(ChainRegistry::class),
            new FakeEvidenceRpcClientFactory($rpc),
        );
        $funding = $this->funding();
        $funding->required_confirmations = 4;

        $pending = $provider->inspect($funding);
        self::assertSame(EvmGasFundingReconciliationResult::PENDING, $pending->outcome);

        $rpc->currentBlock = 13;
        $failed = $provider->inspect($funding);
        self::assertSame(EvmGasFundingReconciliationResult::FAILED_SAFE, $failed->outcome);
    }

    public function test_absent_hash_recovers_only_from_unique_bounded_source_nonce_match(): void
    {
        config()->set('chains.evm_local.rpc_url', 'http://evm.test');
        $rpc = new FakeGasFundingRpcClient;
        $rpc->knownHashLookupEnabled = false;
        $provider = new RpcEvmGasFundingEvidenceProvider(
            app(ChainRegistry::class),
            new FakeEvidenceRpcClientFactory($rpc),
        );
        $funding = $this->funding();
        $funding->tx_hash = null;

        $result = $provider->inspect($funding);

        self::assertSame(EvmGasFundingReconciliationResult::CONFIRMED, $result->outcome);
        self::assertSame($rpc->txHash, $result->txHash);

        $rpc->blockTransactions[] = $rpc->transaction;
        $ambiguous = $provider->inspect($funding);
        self::assertSame(EvmGasFundingReconciliationResult::INCONCLUSIVE, $ambiguous->outcome);
        self::assertSame('evm_source_nonce_search_inconclusive', $ambiguous->reason);
    }

    private function funding(): EvmGasFunding
    {
        return new EvmGasFunding([
            'network_key' => 'evm_local',
            'source_address' => '0x1111111111111111111111111111111111111111',
            'target_address' => '0x2222222222222222222222222222222222222222',
            'amount_native_wei' => '1000000000000000',
            'tx_hash' => '0x'.str_repeat('a', 64),
            'chain_id' => '31337',
            'nonce' => '7',
            'required_confirmations' => 3,
            'broadcast_block_number' => 10,
        ]);
    }
}

final class FakeEvidenceRpcClientFactory extends EvmRpcClientFactory
{
    public function __construct(private readonly EvmRpcClient $client) {}

    public function make(string $rpcUrl): EvmRpcClient
    {
        return $this->client;
    }
}

final class FakeGasFundingRpcClient extends EvmRpcClient
{
    public string $txHash = '0xaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    public int $currentBlock = 12;

    public bool $knownHashLookupEnabled = true;

    /** @var array<string, mixed> */
    public array $transaction;

    /** @var array<string, mixed> */
    public array $receipt;

    /** @var array<int, array<string, mixed>> */
    public array $blockTransactions;

    public function __construct()
    {
        $this->transaction = [
            'hash' => $this->txHash,
            'from' => '0x1111111111111111111111111111111111111111',
            'to' => '0x2222222222222222222222222222222222222222',
            'nonce' => '0x7',
            'value' => '0x38d7ea4c68000',
            'chainId' => '0x7a69',
        ];
        $this->receipt = [
            'transactionHash' => $this->txHash,
            'blockNumber' => '0xa',
            'status' => '0x1',
        ];
        $this->blockTransactions = [$this->transaction];
    }

    public function chainId(): int
    {
        return 31337;
    }

    public function getTransactionByHash(string $txHash): ?array
    {
        return $this->knownHashLookupEnabled ? $this->transaction : null;
    }

    public function getTransactionReceipt(string $txHash): ?array
    {
        return $this->receipt;
    }

    public function blockNumber(): int
    {
        return $this->currentBlock;
    }

    public function getBlockByNumber(string $block = 'latest', bool $fullTransactions = false): ?array
    {
        return ['transactions' => $block === '0xa' ? $this->blockTransactions : []];
    }
}
