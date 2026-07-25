<?php

declare(strict_types=1);

namespace Tests\Integration\RealChains;

use App\Contracts\EvmAddressDeriverInterface;
use App\Models\AssetPolicy;
use App\Models\EvmGasFunding;
use App\Models\Invoice;
use App\Models\MerchantSettlementAttempt;
use App\Models\PaymentAddress;
use App\Models\SuperWallet;
use App\Services\Evm\EvmGasFundingReconciler;
use App\Services\Evm\EvmRpcClient;
use App\Services\InvoiceForwarder;
use App\Services\Settlement\SettlementAttemptReconciler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\BuildsDomainData;
use Tests\TestCase;

final class RealEvmSettlementReconciliationTest extends TestCase
{
    use BuildsDomainData;
    use RefreshDatabase;

    private EvmRpcClient $rpc;

    private ?string $snapshotId = null;

    protected function setUp(): void
    {
        parent::setUp();

        if (! (bool) env('RUN_REAL_RPC_TESTS', false)) {
            $this->markTestSkipped('Set RUN_REAL_RPC_TESTS=true to run real Anvil reconciliation tests.');
        }

        $rpcUrl = trim((string) config('chains.evm_local.rpc_url'));
        self::assertNotSame('', $rpcUrl, 'EVM_LOCAL_RPC_URL is required for Anvil integration tests.');
        $this->rpc = new EvmRpcClient($rpcUrl, 10);
        self::assertSame(31337, $this->rpc->chainId(), 'Anvil must use chain ID 31337.');
        $snapshot = $this->rpc->call('anvil_snapshot');
        self::assertIsString($snapshot);
        $this->snapshotId = $snapshot;

        config()->set('webhooks.enabled', false);
        config()->set('forwarding.enabled', true);
    }

    protected function tearDown(): void
    {
        if (isset($this->rpc) && $this->snapshotId !== null) {
            $this->rpc->call('anvil_revert', [$this->snapshotId]);
        }

        parent::tearDown();
    }

    public function test_anvil_native_payout_is_completed_only_after_receipt_reconciliation(): void
    {
        $source = '0xf39fd6e51aad88f6f4ce6ab8827279cfffb92266';
        $destination = '0x70997970c51812dc3a010c7d01b50e0d17dc79c8';
        $merchant = $this->createMerchant(['fee_percent' => '0']);
        $invoice = $this->paidInvoice($merchant->id, 'eth_local', '0.001000000000000000', $source);
        $this->paymentAddress($invoice, $source, 'dev_rpc_account');
        $this->wallet($merchant->id, 'eth_local', $destination);
        $this->immediatePolicy('eth_local');

        app(InvoiceForwarder::class)->forward($invoice->id);

        $attempt = MerchantSettlementAttempt::query()->where('invoice_id', $invoice->id)->sole();
        if ($attempt->state !== MerchantSettlementAttempt::STATE_COMPLETED) {
            app(SettlementAttemptReconciler::class)->reconcile($attempt->id, true);
        }

        self::assertSame(MerchantSettlementAttempt::STATE_COMPLETED, $attempt->fresh()->state);
        self::assertSame(Invoice::FORWARD_STATUS_DONE, $invoice->fresh()->forward_status);
        self::assertNotNull($attempt->fresh()->txid);
        self::assertDatabaseHas('merchant_settlement_entries', [
            'invoice_id' => $invoice->id,
            'settlement_attempt_id' => $attempt->id,
            'status' => 'completed',
        ]);
    }

    public function test_anvil_erc20_gas_funding_is_confirmed_before_token_payout_reconciliation(): void
    {
        config()->set('payment_addresses.evm.gas_topup.enabled', true);
        config()->set('payment_addresses.evm.gas_topup.target_min_native_decimal', '0.0002');
        config()->set('payment_addresses.evm.gas_topup.safety_buffer_decimal', '0.00005');

        $contract = strtolower(trim((string) config('assets.eth_usdt_local.contract_address')));
        self::assertMatchesRegularExpression(
            '/^0x[a-f0-9]{40}$/',
            $contract,
            'EVM_LOCAL_USDT_CONTRACT_ADDRESS must point to the deployed Anvil test token.',
        );

        $deriver = app(EvmAddressDeriverInterface::class);
        $source = $deriver->derive(
            'evm_local',
            (string) config('payment_addresses.evm.default_key_refs.evm_local'),
            91,
            (string) config('payment_addresses.evm.derivation_path_template'),
        );
        $gasStation = $deriver->derive(
            'evm_local',
            (string) config('payment_addresses.evm.gas_station_key_refs.evm_local'),
            0,
            (string) config('payment_addresses.evm.gas_station_derivation_path_template'),
        );
        $sourceAddress = strtolower($source->address);
        $gasStationAddress = strtolower($gasStation->address);
        self::assertNotSame($sourceAddress, $gasStationAddress);

        $this->rpc->call('anvil_setBalance', [$sourceAddress, '0x0']);
        $this->rpc->call('anvil_setBalance', [$gasStationAddress, '0x56bc75e2d63100000']);
        $tokenFundingHash = (string) $this->rpc->call('eth_sendTransaction', [[
            'from' => '0xf39fd6e51aad88f6f4ce6ab8827279cfffb92266',
            'to' => $contract,
            'data' => $this->rpc->encodeErc20TransferData($sourceAddress, '2000000'),
        ]]);
        $tokenFundingReceipt = $this->waitForReceipt($tokenFundingHash);
        self::assertSame('0x1', strtolower((string) ($tokenFundingReceipt['status'] ?? '')));

        $merchant = $this->createMerchant(['fee_percent' => '0']);
        $invoice = $this->paidInvoice($merchant->id, 'eth_usdt_local', '1.000000', $sourceAddress);
        $this->paymentAddress(
            $invoice,
            $sourceAddress,
            'hd_derived',
            (string) $source->derivationPath,
            $source->derivationIndex,
        );
        $this->wallet($merchant->id, 'eth_usdt_local', '0x70997970c51812dc3a010c7d01b50e0d17dc79c8');
        $this->immediatePolicy('eth_usdt_local');

        app(InvoiceForwarder::class)->forward($invoice->id);

        $funding = EvmGasFunding::query()->where('invoice_id', $invoice->id)->sole();
        self::assertSame(EvmGasFunding::STATE_BROADCASTED, $funding->state);
        self::assertNotNull($funding->tx_hash);

        app(EvmGasFundingReconciler::class)->reconcile($funding->id, true);

        self::assertSame(EvmGasFunding::STATE_CONFIRMED, $funding->fresh()->state);
        $attempt = MerchantSettlementAttempt::query()
            ->where('invoice_id', $invoice->id)
            ->latest('id')
            ->firstOrFail();
        if ($attempt->state !== MerchantSettlementAttempt::STATE_COMPLETED) {
            if ($attempt->state === MerchantSettlementAttempt::STATE_FAILED && $attempt->retry_safe) {
                app(InvoiceForwarder::class)->forward($invoice->id);
                $attempt = MerchantSettlementAttempt::query()
                    ->where('invoice_id', $invoice->id)
                    ->latest('id')
                    ->firstOrFail();
            }
            app(SettlementAttemptReconciler::class)->reconcile($attempt->id, true);
        }

        self::assertSame(MerchantSettlementAttempt::STATE_COMPLETED, $attempt->fresh()->state);
        self::assertSame(MerchantSettlementAttempt::TRANSFER_ERC20, $attempt->fresh()->transfer_type);
        self::assertSame(Invoice::FORWARD_STATUS_DONE, $invoice->fresh()->forward_status);
    }

    private function paidInvoice(int $merchantId, string $assetKey, string $amount, string $source): Invoice
    {
        return Invoice::query()->create([
            'merchant_id' => $merchantId,
            'public_id' => strtolower(bin2hex(random_bytes(8))),
            'status' => 'paid',
            'coin' => $assetKey,
            'asset_key' => $assetKey,
            'network_key' => 'evm_local',
            'pay_address' => $source,
            'amount_coin' => $amount,
            'expected_usd' => '1.00',
            'rate_usd' => '1.00000000',
            'received_conf_coin' => $amount,
            'received_all_coin' => $amount,
            'fee_coin' => '0',
            'merchant_payout_coin' => $amount,
            'settlement_snapshot_locked_at' => now('UTC'),
            'forwarded_coin' => '0',
            'forward_status' => Invoice::FORWARD_STATUS_NONE,
            'metadata' => [],
        ]);
    }

    private function paymentAddress(
        Invoice $invoice,
        string $address,
        string $strategy,
        ?string $derivationPath = null,
        ?int $derivationIndex = null,
    ): void {
        PaymentAddress::query()->create([
            'merchant_id' => $invoice->merchant_id,
            'invoice_id' => $invoice->id,
            'network_key' => 'evm_local',
            'asset_key' => $invoice->asset_key,
            'address' => strtolower($address),
            'family' => 'evm',
            'address_type' => 'deposit',
            'strategy' => $strategy,
            'status' => 'assigned',
            'derivation_path' => $derivationPath,
            'derivation_index' => $derivationIndex,
            'key_ref' => (string) config('payment_addresses.evm.default_key_refs.evm_local'),
            'issued_at' => now('UTC'),
            'assigned_at' => now('UTC'),
            'meta' => [],
        ]);
    }

    private function wallet(int $merchantId, string $assetKey, string $address): void
    {
        SuperWallet::query()->create([
            'merchant_id' => $merchantId,
            'coin' => $assetKey,
            'asset_key' => $assetKey,
            'network_key' => 'evm_local',
            'wallet' => strtolower($address),
            'fee_rate' => null,
        ]);
    }

    private function immediatePolicy(string $assetKey): void
    {
        AssetPolicy::query()->create([
            'asset_key' => $assetKey,
            'network_key' => 'evm_local',
            'asset_enabled' => true,
            'checkout_enabled' => true,
            'forwarding_enabled' => true,
            'settlement_mode' => AssetPolicy::MODE_IMMEDIATE,
        ]);
    }

    /** @return array<string, mixed> */
    private function waitForReceipt(string $txHash, int $attempts = 20): array
    {
        for ($attempt = 0; $attempt < $attempts; $attempt++) {
            $receipt = $this->rpc->getTransactionReceipt($txHash);
            if ($receipt !== null) {
                return $receipt;
            }

            usleep(100000);
        }

        self::fail("Anvil did not return a receipt for transaction [{$txHash}].");
    }
}
