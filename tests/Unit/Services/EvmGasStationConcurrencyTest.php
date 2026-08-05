<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Contracts\EvmAddressDeriverInterface;
use App\Contracts\EvmTransactionSignerInterface;
use App\Data\EvmSweepSource;
use App\Exceptions\EvmGasFundingAttemptNotAuthorizedException;
use App\Exceptions\ForwardingConfigurationException;
use App\Models\EvmGasFunding;
use App\Models\Invoice;
use App\Models\MerchantSettlementAttempt;
use App\Models\SuperWallet;
use App\Services\Evm\EvmGasTopUpService;
use App\Services\Evm\EvmRpcClient;
use App\Services\Evm\EvmRpcClientFactory;
use App\Services\Evm\EvmTokenGasChecker;
use App\Services\PaymentAddresses\Evm\DerivedAddressResult;
use App\Services\Settlement\MerchantSettlementAttemptManager;
use App\Support\Assets\AssetRegistry;
use App\Support\Chains\ChainRegistry;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BuildsDomainData;
use Tests\TestCase;

final class EvmGasStationConcurrencyTest extends TestCase
{
    use BuildsDomainData;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->enableForwardingForTests();
    }

    public function test_two_erc20_invoices_cannot_allocate_concurrent_gas_station_nonces(): void
    {
        $this->configureGasTopUp();

        $rpc = new FakeGasStationRpcClient;
        $signer = new FakeGasStationSigner;
        $service = $this->gasTopUpService($rpc, $signer);
        $merchant = $this->createMerchant();
        $first = $this->reservableErc20Invoice($merchant);
        $second = $this->reservableErc20Invoice($merchant);
        $destination = new SuperWallet([
            'id' => 1,
            'wallet' => '0x4444444444444444444444444444444444444444',
        ]);
        $firstOwner = (string) Str::uuid();
        $secondOwner = (string) Str::uuid();
        $firstAttempt = $this->reserveErc20Attempt($first, $destination, $firstOwner);
        $secondAttempt = $this->reserveErc20Attempt($second, $destination, $secondOwner);
        $baselineTransactionLevel = DB::transactionLevel();
        $signer->beforeSign = function () use ($baselineTransactionLevel, $firstAttempt): void {
            self::assertSame($baselineTransactionLevel, DB::transactionLevel());
            $funding = EvmGasFunding::query()->sole();
            self::assertSame(EvmGasFunding::STATE_BROADCASTING, $funding->state);
            self::assertSame($firstAttempt->id, $funding->meta['settlement_attempt_id']);
            self::assertSame($firstAttempt->attempt_uuid, $funding->meta['settlement_attempt_uuid']);
        };

        $firstOutcome = $service->ensureTopUpForErc20Transfer(
            invoice: $first,
            settlementAttemptId: $firstAttempt->id,
            settlementAttemptUuid: $firstAttempt->attempt_uuid,
            ownerToken: $firstOwner,
            source: new EvmSweepSource('evm_local', '0x5555555555555555555555555555555555555555', 'vault:first'),
            destination: $destination,
            amountDecimal: '10.000000',
        );
        $secondOutcome = $service->ensureTopUpForErc20Transfer(
            invoice: $second,
            settlementAttemptId: $secondAttempt->id,
            settlementAttemptUuid: $secondAttempt->attempt_uuid,
            ownerToken: $secondOwner,
            source: new EvmSweepSource('evm_local', '0x6666666666666666666666666666666666666666', 'vault:second'),
            destination: $destination,
            amountDecimal: '10.000000',
        );

        self::assertSame('funded', $firstOutcome->status);
        self::assertSame('awaiting_previous', $secondOutcome->status);
        self::assertSame(1, $rpc->nonceReads);
        self::assertSame(1, $signer->calls);
        self::assertSame(1, EvmGasFunding::query()->count());
        self::assertSame(EvmGasFunding::STATE_BROADCASTED, EvmGasFunding::query()->sole()->state);
    }

    public function test_disable_before_gas_funding_boundary_never_calls_signer_and_releases_attempt(): void
    {
        $this->configureGasTopUp();

        $rpc = new FakeGasStationRpcClient;
        $signer = new FakeGasStationSigner;
        $service = $this->gasTopUpService($rpc, $signer);
        $merchant = $this->createMerchant();
        $invoice = $this->reservableErc20Invoice($merchant);
        $destination = new SuperWallet([
            'id' => 1,
            'wallet' => '0x4444444444444444444444444444444444444444',
        ]);
        $ownerToken = (string) Str::uuid();
        $attempt = $this->reserveErc20Attempt($invoice, $destination, $ownerToken);

        $this->disableForwardingForTests('gas_funding_boundary_disabled');
        $outcome = $service->ensureTopUpForErc20Transfer(
            invoice: $invoice,
            settlementAttemptId: $attempt->id,
            settlementAttemptUuid: $attempt->attempt_uuid,
            ownerToken: $ownerToken,
            source: new EvmSweepSource(
                'evm_local',
                '0x5555555555555555555555555555555555555555',
                'vault:deposit',
            ),
            destination: $destination,
            amountDecimal: '10.000000',
        );

        self::assertSame('forwarding_disabled', $outcome->status);
        self::assertTrue($outcome->requiresDeferredPayout);
        self::assertSame(0, $signer->calls);
        self::assertSame(0, EvmGasFunding::query()->count());
        self::assertSame(MerchantSettlementAttempt::STATE_FAILED, $attempt->fresh()->state);
        self::assertTrue($attempt->fresh()->retry_safe);
        self::assertSame(
            'forwarding_disabled_before_gas_funding',
            $attempt->fresh()->error_message,
        );
        self::assertSame(Invoice::FORWARD_STATUS_FAILED, $invoice->fresh()->forward_status);
        self::assertNull($invoice->fresh()->forward_attempt_uuid);
        self::assertNull($invoice->fresh()->forwarding_coin);
        self::assertNull($invoice->fresh()->forwarding_started_at);
    }

    public function test_invalid_config_closes_exact_owned_attempt_without_signing(): void
    {
        [$service, $signer, $invoice, $destination] = $this->gasFundingFixture();
        $ownerToken = (string) Str::uuid();
        $attempt = $this->reserveErc20Attempt($invoice, $destination, $ownerToken);
        config()->set('forwarding.enabled', 'flase');

        try {
            $this->requestFunding($service, $invoice, $attempt, $ownerToken, $destination);
            self::fail('Invalid forwarding config crossed the gas-funding boundary.');
        } catch (ForwardingConfigurationException $exception) {
            self::assertSame(
                'forwarding_configuration_invalid_before_gas_funding',
                $exception->getMessage(),
            );
        }

        self::assertSame(0, $signer->calls);
        self::assertSame(0, EvmGasFunding::query()->count());
        self::assertSame(MerchantSettlementAttempt::STATE_FAILED, $attempt->fresh()->state);
        self::assertTrue($attempt->fresh()->retry_safe);
        self::assertSame(
            'forwarding_configuration_invalid_before_gas_funding',
            $attempt->fresh()->error_message,
        );
        self::assertNull($attempt->fresh()->lease_owner_token);
        self::assertNull($invoice->fresh()->forward_attempt_uuid);
    }

    public function test_enabled_gate_without_active_attempt_never_creates_funding_or_calls_signer(): void
    {
        [$service, $signer, $invoice, $destination] = $this->gasFundingFixture();

        $this->assertFundingRejected(
            fn () => $service->ensureTopUpForErc20Transfer(
                invoice: $invoice,
                settlementAttemptId: 999999,
                settlementAttemptUuid: (string) Str::uuid(),
                ownerToken: (string) Str::uuid(),
                source: $this->depositSource(),
                destination: $destination,
                amountDecimal: '10.000000',
            ),
            'settlement_attempt_missing_before_gas_funding',
        );

        self::assertSame(0, $signer->calls);
        self::assertSame(0, EvmGasFunding::query()->count());
        self::assertNull($invoice->fresh()->forward_attempt_uuid);
    }

    public function test_non_reserved_exact_attempt_never_creates_funding_or_calls_signer(): void
    {
        [$service, $signer, $invoice, $destination] = $this->gasFundingFixture();
        $ownerToken = (string) Str::uuid();
        $attempt = $this->reserveErc20Attempt($invoice, $destination, $ownerToken);
        app(MerchantSettlementAttemptManager::class)->markBroadcasting(
            attemptId: $attempt->id,
            ownerToken: $ownerToken,
        );

        $this->assertFundingRejected(
            fn () => $this->requestFunding($service, $invoice, $attempt, $ownerToken, $destination),
            'settlement_attempt_not_reserved_before_gas_funding',
        );

        self::assertSame(0, $signer->calls);
        self::assertSame(0, EvmGasFunding::query()->count());
        self::assertSame(MerchantSettlementAttempt::STATE_BROADCASTING, $attempt->fresh()->state);
        self::assertFalse($attempt->fresh()->retry_safe);
        self::assertSame($attempt->attempt_uuid, $invoice->fresh()->forward_attempt_uuid);
    }

    public function test_stale_attempt_cannot_fund_or_change_newer_active_attempt(): void
    {
        [$service, $signer, $invoice, $destination] = $this->gasFundingFixture();
        $staleOwner = (string) Str::uuid();
        $staleAttempt = $this->reserveErc20Attempt($invoice, $destination, $staleOwner);
        app(MerchantSettlementAttemptManager::class)->markPreBroadcastFailed($staleAttempt->id, 'test_retry');
        $newOwner = (string) Str::uuid();
        $newAttempt = $this->reserveErc20Attempt($invoice->fresh(), $destination, $newOwner);
        $newLease = $newAttempt->lease_expires_at->toJSON();

        $this->assertFundingRejected(
            fn () => $this->requestFunding($service, $invoice, $staleAttempt, $staleOwner, $destination),
            'settlement_attempt_replaced_before_gas_funding',
        );

        self::assertSame(0, $signer->calls);
        self::assertSame(0, EvmGasFunding::query()->count());
        self::assertSame(MerchantSettlementAttempt::STATE_RESERVED, $newAttempt->fresh()->state);
        self::assertSame($newOwner, $newAttempt->fresh()->lease_owner_token);
        self::assertSame($newLease, $newAttempt->fresh()->lease_expires_at->toJSON());
        self::assertSame($newAttempt->attempt_uuid, $invoice->fresh()->forward_attempt_uuid);
        self::assertSame(MerchantSettlementAttempt::STATE_FAILED, $staleAttempt->fresh()->state);
    }

    public function test_owner_token_mismatch_never_funds_or_mutates_legitimate_attempt(): void
    {
        [$service, $signer, $invoice, $destination] = $this->gasFundingFixture();
        $ownerToken = (string) Str::uuid();
        $attempt = $this->reserveErc20Attempt($invoice, $destination, $ownerToken);
        $lease = $attempt->lease_expires_at->toJSON();

        $this->assertFundingRejected(
            fn () => $this->requestFunding($service, $invoice, $attempt, (string) Str::uuid(), $destination),
            'settlement_attempt_owner_mismatch_before_gas_funding',
        );

        self::assertSame(0, $signer->calls);
        self::assertSame(0, EvmGasFunding::query()->count());
        self::assertSame(MerchantSettlementAttempt::STATE_RESERVED, $attempt->fresh()->state);
        self::assertSame($ownerToken, $attempt->fresh()->lease_owner_token);
        self::assertSame($lease, $attempt->fresh()->lease_expires_at->toJSON());
        self::assertSame($attempt->attempt_uuid, $invoice->fresh()->forward_attempt_uuid);
    }

    public function test_expired_lease_never_creates_funding_or_calls_signer(): void
    {
        [$service, $signer, $invoice, $destination] = $this->gasFundingFixture();
        $ownerToken = (string) Str::uuid();
        $attempt = $this->reserveErc20Attempt($invoice, $destination, $ownerToken);
        $attempt->forceFill(['lease_expires_at' => now('UTC')->subSecond()])->save();

        $this->assertFundingRejected(
            fn () => $this->requestFunding($service, $invoice, $attempt, $ownerToken, $destination),
            'settlement_attempt_lease_expired_before_gas_funding',
        );

        self::assertSame(0, $signer->calls);
        self::assertSame(0, EvmGasFunding::query()->count());
        self::assertSame(MerchantSettlementAttempt::STATE_RESERVED, $attempt->fresh()->state);
        self::assertSame($ownerToken, $attempt->fresh()->lease_owner_token);
        self::assertSame($attempt->attempt_uuid, $invoice->fresh()->forward_attempt_uuid);
    }

    #[DataProvider('invalidAttemptIdentityCases')]
    public function test_attempt_identity_and_server_attributes_must_match_exactly(
        string $case,
        string $expectedReason,
    ): void {
        [$service, $signer, $invoice, $destination] = $this->gasFundingFixture();
        $ownerToken = (string) Str::uuid();
        $attempt = $this->reserveErc20Attempt($invoice, $destination, $ownerToken);
        $attemptUuid = $attempt->attempt_uuid;

        if ($case === 'uuid') {
            $attemptUuid = (string) Str::uuid();
        } elseif ($case === 'invoice') {
            app(MerchantSettlementAttemptManager::class)->markPreBroadcastFailed($attempt->id, 'replace_fixture');
            $foreignInvoice = $this->reservableErc20Invoice($this->createMerchant());
            $attempt = $this->reserveErc20Attempt($foreignInvoice, $destination, $ownerToken);
            $attemptUuid = $attempt->attempt_uuid;
        } elseif ($case === 'merchant') {
            $attempt->forceFill(['merchant_id' => $this->createMerchant()->id])->save();
        } elseif ($case === 'transfer') {
            $attempt->forceFill(['transfer_type' => MerchantSettlementAttempt::TRANSFER_EVM_NATIVE])->save();
        } elseif ($case === 'asset_network') {
            $attempt->forceFill([
                'asset_key' => 'eth_local',
                'network_key' => 'another_evm_network',
            ])->save();
        }

        $this->assertFundingRejected(
            fn () => $service->ensureTopUpForErc20Transfer(
                invoice: $invoice,
                settlementAttemptId: $attempt->id,
                settlementAttemptUuid: $attemptUuid,
                ownerToken: $ownerToken,
                source: $this->depositSource(),
                destination: $destination,
                amountDecimal: '10.000000',
            ),
            $expectedReason,
        );

        self::assertSame(0, $signer->calls);
        self::assertSame(0, EvmGasFunding::query()->count());
        self::assertSame(MerchantSettlementAttempt::STATE_RESERVED, $attempt->fresh()->state);
        self::assertSame($ownerToken, $attempt->fresh()->lease_owner_token);
    }

    /** @return iterable<string, array{string, string}> */
    public static function invalidAttemptIdentityCases(): iterable
    {
        yield 'UUID mismatch' => ['uuid', 'settlement_attempt_identity_mismatch_before_gas_funding'];
        yield 'foreign invoice' => ['invoice', 'settlement_attempt_identity_mismatch_before_gas_funding'];
        yield 'foreign merchant' => ['merchant', 'settlement_attempt_identity_mismatch_before_gas_funding'];
        yield 'non-ERC-20 transfer' => ['transfer', 'settlement_attempt_not_erc20_before_gas_funding'];
        yield 'asset and network mismatch' => ['asset_network', 'settlement_attempt_asset_network_mismatch_before_gas_funding'];
    }

    /** @return array{EvmGasTopUpService, FakeGasStationSigner, Invoice, SuperWallet} */
    private function gasFundingFixture(): array
    {
        $this->configureGasTopUp();
        $signer = new FakeGasStationSigner;
        $service = $this->gasTopUpService(new FakeGasStationRpcClient, $signer);
        $invoice = $this->reservableErc20Invoice($this->createMerchant());
        $destination = new SuperWallet([
            'id' => 1,
            'wallet' => '0x4444444444444444444444444444444444444444',
        ]);

        return [$service, $signer, $invoice, $destination];
    }

    private function configureGasTopUp(): void
    {
        config()->set('payment_addresses.evm.gas_topup.enabled', true);
        config()->set('payment_addresses.evm.gas_topup.target_min_native_wei', '250000');
        config()->set('payment_addresses.evm.gas_topup.safety_buffer_wei', '0');
        config()->set('payment_addresses.evm.gas_station_key_refs.evm_local', 'vault:gas-station');
        config()->set('chains.evm_local.rpc_url', 'http://evm.test');
        config()->set('assets.eth_usdt_local.contract_address', '0x3333333333333333333333333333333333333333');
    }

    private function gasTopUpService(
        FakeGasStationRpcClient $rpc,
        FakeGasStationSigner $signer,
    ): EvmGasTopUpService {
        return new EvmGasTopUpService(
            app(ChainRegistry::class),
            app(AssetRegistry::class),
            new FakeGasStationAddressDeriver,
            $signer,
            new EvmTokenGasChecker,
            new FakeGasStationRpcClientFactory($rpc),
            app(\App\Services\Evm\EvmGasFundingBoundary::class),
        );
    }

    private function reservableErc20Invoice(\App\Models\Merchant $merchant): Invoice
    {
        return $this->createInvoice($merchant, [
            'status' => 'paid',
            'coin' => 'eth_usdt_local',
            'asset_key' => 'eth_usdt_local',
            'network_key' => 'evm_local',
            'received_conf_coin' => '10.000000',
            'fee_coin' => '0.000000',
            'merchant_payout_coin' => '10.000000',
            'settlement_snapshot_locked_at' => now('UTC'),
            'forward_status' => Invoice::FORWARD_STATUS_NONE,
        ]);
    }

    private function reserveErc20Attempt(
        Invoice $invoice,
        SuperWallet $destination,
        string $ownerToken,
    ): MerchantSettlementAttempt {
        $attempt = app(MerchantSettlementAttemptManager::class)->reserve(
            invoiceId: $invoice->id,
            chainFamily: 'evm',
            transferType: MerchantSettlementAttempt::TRANSFER_ERC20,
            destinationAddress: (string) $destination->wallet,
            ownerToken: $ownerToken,
        );
        self::assertNotNull($attempt);

        return $attempt;
    }

    private function requestFunding(
        EvmGasTopUpService $service,
        Invoice $invoice,
        MerchantSettlementAttempt $attempt,
        string $ownerToken,
        SuperWallet $destination,
    ): \App\Data\EvmGasTopUpOutcome {
        return $service->ensureTopUpForErc20Transfer(
            invoice: $invoice,
            settlementAttemptId: $attempt->id,
            settlementAttemptUuid: $attempt->attempt_uuid,
            ownerToken: $ownerToken,
            source: $this->depositSource(),
            destination: $destination,
            amountDecimal: '10.000000',
        );
    }

    private function depositSource(): EvmSweepSource
    {
        return new EvmSweepSource(
            'evm_local',
            '0x5555555555555555555555555555555555555555',
            'vault:deposit',
        );
    }

    private function assertFundingRejected(Closure $operation, string $reason): void
    {
        try {
            $operation();
            self::fail("Expected gas funding rejection [{$reason}].");
        } catch (EvmGasFundingAttemptNotAuthorizedException $exception) {
            self::assertSame($reason, $exception->getMessage());
        }
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

    public ?Closure $beforeSign = null;

    public function signTransaction(string $networkKey, EvmSweepSource $source, array $transaction): array
    {
        $this->calls++;
        ($this->beforeSign ?? static fn () => null)();

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
