<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Contracts\EvmAddressDeriverInterface;
use App\Contracts\EvmTransactionSignerInterface;
use App\Data\EvmSweepSource;
use App\Models\EvmGasFunding;
use App\Services\Evm\EvmGasTopUpService;
use App\Services\Evm\EvmRpcClient;
use App\Services\Evm\EvmRpcClientFactory;
use App\Services\Evm\EvmTokenGasChecker;
use App\Services\PaymentAddresses\Evm\DerivedAddressResult;
use App\Support\Assets\AssetRegistry;
use App\Support\Chains\ChainRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\BuildsDomainData;
use Tests\TestCase;

final class EvmGasStationConcurrencyTest extends TestCase
{
    use BuildsDomainData;
    use RefreshDatabase;

    public function test_two_erc20_invoices_cannot_allocate_concurrent_gas_station_nonces(): void
    {
        config()->set('payment_addresses.evm.gas_topup.enabled', true);
        config()->set('payment_addresses.evm.gas_topup.target_min_native_wei', '250000');
        config()->set('payment_addresses.evm.gas_topup.safety_buffer_wei', '0');
        config()->set('payment_addresses.evm.gas_station_key_refs.evm_local', 'vault:gas-station');
        config()->set('chains.evm_local.rpc_url', 'http://evm.test');
        config()->set('assets.eth_usdt_local.contract_address', '0x3333333333333333333333333333333333333333');

        $rpc = new FakeGasStationRpcClient;
        $signer = new FakeGasStationSigner;
        $service = new EvmGasTopUpService(
            app(ChainRegistry::class),
            app(AssetRegistry::class),
            new FakeGasStationAddressDeriver,
            $signer,
            new EvmTokenGasChecker,
            new FakeGasStationRpcClientFactory($rpc),
        );
        $merchant = $this->createMerchant();
        $first = $this->createInvoice($merchant, [
            'coin' => 'eth_usdt_local',
            'asset_key' => 'eth_usdt_local',
            'network_key' => 'evm_local',
        ]);
        $second = $this->createInvoice($merchant, [
            'coin' => 'eth_usdt_local',
            'asset_key' => 'eth_usdt_local',
            'network_key' => 'evm_local',
        ]);
        $destination = new \App\Models\SuperWallet([
            'id' => 1,
            'wallet' => '0x4444444444444444444444444444444444444444',
        ]);

        $firstOutcome = $service->ensureTopUpForErc20Transfer(
            $first,
            new EvmSweepSource('evm_local', '0x5555555555555555555555555555555555555555', 'vault:first'),
            $destination,
            '10.000000',
        );
        $secondOutcome = $service->ensureTopUpForErc20Transfer(
            $second,
            new EvmSweepSource('evm_local', '0x6666666666666666666666666666666666666666', 'vault:second'),
            $destination,
            '10.000000',
        );

        self::assertSame('funded', $firstOutcome->status);
        self::assertSame('awaiting_previous', $secondOutcome->status);
        self::assertSame(1, $rpc->nonceReads);
        self::assertSame(1, $signer->calls);
        self::assertSame(1, EvmGasFunding::query()->count());
        self::assertSame(EvmGasFunding::STATE_BROADCASTED, EvmGasFunding::query()->sole()->state);
    }
}

final class FakeGasStationAddressDeriver implements EvmAddressDeriverInterface
{
    public function derive(string $networkKey, string $keyRef, int $index, string $pathTemplate): DerivedAddressResult
    {
        return new DerivedAddressResult(
            '0x1111111111111111111111111111111111111111',
            "m/44'/60'/100'/0/0",
            0,
            $keyRef,
        );
    }
}

final class FakeGasStationSigner implements EvmTransactionSignerInterface
{
    public int $calls = 0;

    public function signTransaction(string $networkKey, EvmSweepSource $source, array $transaction): array
    {
        $this->calls++;

        return [
            'raw_tx' => '0xraw',
            'tx_hash' => '0x'.str_repeat('a', 64),
        ];
    }
}

final class FakeGasStationRpcClientFactory extends EvmRpcClientFactory
{
    public function __construct(private readonly EvmRpcClient $client) {}

    public function make(string $rpcUrl): EvmRpcClient
    {
        return $this->client;
    }
}

final class FakeGasStationRpcClient extends EvmRpcClient
{
    public int $nonceReads = 0;

    public function __construct() {}

    public function chainId(): int
    {
        return 31337;
    }

    public function gasPriceWei(): string
    {
        return '1';
    }

    public function getBalanceWei(string $address, string $block = 'latest'): string
    {
        return strtolower($address) === '0x1111111111111111111111111111111111111111'
            ? '1000000000'
            : '0';
    }

    public function estimateGas(array $transaction): string
    {
        return isset($transaction['data']) ? '100000' : '21000';
    }

    public function getTransactionCount(string $address, string $block = 'pending'): int
    {
        $this->nonceReads++;

        return 7;
    }

    public function blockNumber(): int
    {
        return 100;
    }
}
