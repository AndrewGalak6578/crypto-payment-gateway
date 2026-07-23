<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\EvmGasTopUpServiceInterface;
use App\Contracts\EvmPayoutSenderInterface;
use App\Contracts\EvmSweepSourceResolverInterface;
use App\Contracts\EvmTokenPayoutSenderInterface;
use App\Data\EvmPayoutResult;
use App\Data\SettlementPolicyDecision;
use App\Exceptions\EvmGasTopUpDeferredException;
use App\Jobs\ReconcileSettlementAttemptJob;
use App\Models\AssetPolicy;
use App\Models\Invoice;
use App\Models\MerchantSettlementAttempt;
use App\Models\SuperWallet;
use App\Services\Settlement\MerchantBalanceCreditor;
use App\Services\Settlement\MerchantSettlementAttemptManager;
use App\Services\Settlement\MerchantSettlementLedger;
use App\Services\Settlement\SettlementAmountCalculator;
use App\Services\Settlement\SettlementDecimal;
use App\Services\Settlement\SuperWalletResolver;
use App\Services\Webhooks\EnqueueInvoiceWebhook;
use App\Support\Assets\AssetRegistry;
use App\Support\Chains\ChainRegistry;
use App\Support\Coin;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Handles merchant settlement after invoice payment.
 */
final class InvoiceForwarder
{
    public function __construct(
        private readonly EnqueueInvoiceWebhook $enqueueWebhook,
        private readonly SuperWalletResolver $walletResolver,
        private readonly MerchantBalanceCreditor $balanceCreditor,
        private readonly MerchantSettlementLedger $settlementLedger,
        private readonly MerchantSettlementAttemptManager $attempts,
        private readonly SettlementAmountCalculator $amounts,
        private readonly SettlementDecimal $decimal,
        private readonly SettlementPolicyResolver $settlementPolicies,
        private readonly AssetRegistry $assets,
        private readonly ChainRegistry $chains,
        private readonly EvmSweepSourceResolverInterface $evmSweepSourceResolver,
        private readonly EvmPayoutSenderInterface $evmPayoutSender,
        private readonly EvmTokenPayoutSenderInterface $evmTokenPayoutSender,
        private readonly EvmGasTopUpServiceInterface $evmGasTopUpService,
    ) {}

    /**
     * Attempts to settle a paid invoice.
     *
     * Once an attempt enters broadcasting, every error is quarantined because
     * the chain may have accepted the payout even when the app has no txid.
     */
    public function forward(int $invoiceId): void
    {
        if ($this->handleExistingAttempt($invoiceId)) {
            return;
        }

        $invoice = $this->prepareSettlementSnapshot($invoiceId);
        if ($invoice === null) {
            return;
        }

        $invoice->loadMissing('paymentAddress');
        $assetKey = $invoice->resolvedAssetKey();
        $networkKey = $invoice->resolvedNetworkKey();
        $settlementPolicy = $this->settlementPolicies->resolveForInvoice($invoice);

        if ($settlementPolicy->reason === 'nothing_to_forward') {
            $this->markForwardingDone($invoiceId);

            return;
        }

        if ($settlementPolicy->shouldCreditInternalBalance()) {
            $this->creditInternalBalance($invoiceId, 'internal_balance_only');

            return;
        }

        if ($settlementPolicy->shouldHold()) {
            $this->holdByPolicy($invoiceId, $settlementPolicy);

            return;
        }

        $wallet = $this->walletResolver->resolveByAsset(
            merchant: $invoice->merchant,
            assetKey: $assetKey,
            networkKey: $networkKey,
        );

        if ($wallet === null) {
            $this->creditInternalBalance($invoiceId, 'destination_wallet_missing');

            return;
        }

        $family = $this->chains->family($networkKey);
        $transferType = match ($family) {
            'utxo' => MerchantSettlementAttempt::TRANSFER_UTXO,
            'evm' => $this->isEvmTokenAsset($assetKey)
                ? MerchantSettlementAttempt::TRANSFER_ERC20
                : MerchantSettlementAttempt::TRANSFER_EVM_NATIVE,
            default => throw new RuntimeException(
                "Unsupported forwarding family [{$family}] for network [{$networkKey}]."
            ),
        };

        $ownerToken = (string) Str::uuid();
        $attempt = $this->attempts->reserve(
            invoiceId: $invoiceId,
            chainFamily: $family,
            transferType: $transferType,
            destinationAddress: (string) $wallet->wallet,
            metadata: [
                'settlement_mode' => $settlementPolicy->mode,
                'settlement_reason' => $settlementPolicy->reason,
                'min_sweep_amount' => $settlementPolicy->minSweepAmount,
                'max_gas_cost' => $settlementPolicy->maxGasCost,
                'fee_rate' => $wallet->fee_rate !== null ? (string) $wallet->fee_rate : null,
            ],
            ownerToken: $ownerToken,
        );

        if ($attempt === null) {
            return;
        }

        try {
            $result = match ($transferType) {
                MerchantSettlementAttempt::TRANSFER_UTXO => $this->forwardUtxo($attempt, $wallet, $ownerToken),
                MerchantSettlementAttempt::TRANSFER_EVM_NATIVE => $this->forwardEvmNative($attempt, $invoice, $wallet, $ownerToken),
                MerchantSettlementAttempt::TRANSFER_ERC20 => $this->forwardEvmErc20($attempt, $invoice, $wallet, $ownerToken),
                default => throw new RuntimeException("Unsupported settlement transfer type [{$transferType}]."),
            };

            $this->attempts->markBroadcasted(
                attemptId: $attempt->id,
                txid: $result->txHash,
                broadcastAmount: $result->amountDecimal,
                nonce: $result->nonce,
                metadata: $result->meta,
            );

            $this->scheduleReconciliation($attempt->id);
        } catch (EvmGasTopUpDeferredException $e) {
            $this->attempts->markPreBroadcastFailed(
                $attempt->id,
                'EVM gas top-up is pending confirmation before token payout.',
            );

            return;
        } catch (Throwable $e) {
            $freshAttempt = $attempt->fresh();

            if ($freshAttempt?->state === MerchantSettlementAttempt::STATE_RESERVED) {
                $this->attempts->markPreBroadcastFailed($attempt->id, $e->getMessage());
            } elseif ($freshAttempt?->state !== MerchantSettlementAttempt::STATE_COMPLETED) {
                $this->attempts->markNeedsReconciliation(
                    attemptId: $attempt->id,
                    errorMessage: $e->getMessage(),
                    metadata: ['failure_class' => $e::class],
                );
            }

            report($e);

            throw $e;
        }

    }

    /**
     * A redelivered job must treat an unfinished broadcast-capable state as
     * ambiguous. It may be the first code running after a worker was killed.
     */
    private function handleExistingAttempt(int $invoiceId): bool
    {
        $attemptUuid = Invoice::query()
            ->whereKey($invoiceId)
            ->value('forward_attempt_uuid');

        if (! is_string($attemptUuid) || $attemptUuid === '') {
            return false;
        }

        $attempt = MerchantSettlementAttempt::query()
            ->where('invoice_id', $invoiceId)
            ->where('attempt_uuid', $attemptUuid)
            ->first();

        if ($attempt === null) {
            return false;
        }

        if (in_array($attempt->state, [
            MerchantSettlementAttempt::STATE_BROADCASTING,
            MerchantSettlementAttempt::STATE_BROADCASTED,
            MerchantSettlementAttempt::STATE_CONFIRMED,
            MerchantSettlementAttempt::STATE_NEEDS_RECONCILIATION,
        ], true)) {
            $this->scheduleReconciliation($attempt->id);
        } elseif ($attempt->state === MerchantSettlementAttempt::STATE_FAILED) {
            $this->attempts->markNeedsReconciliation(
                attemptId: $attempt->id,
                errorMessage: 'Invoice retained a failed attempt as active.',
                metadata: ['failure_stage' => 'inconsistent_active_attempt'],
            );
        }

        return true;
    }

    private function scheduleReconciliation(int $attemptId): void
    {
        ReconcileSettlementAttemptJob::dispatch($attemptId)
            ->delay(now('UTC')->addSeconds(5));
    }

    private function creditInternalBalance(int $invoiceId, string $reason): void
    {
        $this->balanceCreditor->credit($invoiceId, $reason);
    }

    private function holdByPolicy(int $invoiceId, SettlementPolicyDecision $decision): void
    {
        DB::transaction(function () use ($invoiceId, $decision): void {
            /** @var Invoice $invoice */
            $invoice = Invoice::query()
                ->with('merchant')
                ->lockForUpdate()
                ->findOrFail($invoiceId);

            if (
                $invoice->status !== 'paid'
                || ! $invoice->hasRetryableForwardStatus()
                || $invoice->forward_attempt_uuid !== null
            ) {
                return;
            }

            $invoice->forward_status = $decision->mode === AssetPolicy::MODE_MANUAL
                ? Invoice::FORWARD_STATUS_MANUAL
                : Invoice::FORWARD_STATUS_HELD;
            $invoice->save();

            $this->settlementLedger->recordPolicyHold($invoice, $decision);
        });
    }

    /**
     * Locks merchant fee and net payout before any policy or money-movement action.
     */
    private function prepareSettlementSnapshot(int $invoiceId): ?Invoice
    {
        return DB::transaction(function () use ($invoiceId): ?Invoice {
            /** @var Invoice $invoice */
            $invoice = Invoice::query()
                ->with('merchant')
                ->lockForUpdate()
                ->findOrFail($invoiceId);

            if ($invoice->status !== 'paid') {
                return null;
            }

            if ($invoice->forward_attempt_uuid !== null) {
                $attemptExists = MerchantSettlementAttempt::query()
                    ->where('attempt_uuid', $invoice->forward_attempt_uuid)
                    ->where('invoice_id', $invoice->id)
                    ->exists();

                if (! $attemptExists) {
                    $invoice->forward_status = Invoice::FORWARD_STATUS_NEEDS_RECONCILIATION;
                    $invoice->save();
                }

                return null;
            }

            if (
                ! empty($invoice->forward_txids)
                && ! MerchantSettlementAttempt::query()->where('invoice_id', $invoice->id)->exists()
                && BigDecimal::of($this->settlementLedger->completedForwardAmount($invoice))->isZero()
            ) {
                $invoice->forward_status = Invoice::FORWARD_STATUS_NEEDS_RECONCILIATION;
                $invoice->save();

                return null;
            }

            if (
                $invoice->forward_status === Invoice::FORWARD_STATUS_PROCESSING
                || (
                    $invoice->forward_status === Invoice::FORWARD_STATUS_FAILED
                    && ! $invoice->hasRetryableForwardStatus()
                )
            ) {
                $invoice->forward_status = Invoice::FORWARD_STATUS_NEEDS_RECONCILIATION;
                $invoice->save();

                return null;
            }

            if (! $invoice->hasRetryableForwardStatus()) {
                return null;
            }

            if ($invoice->settlement_snapshot_locked_at === null) {
                $invoice->forward_status = Invoice::FORWARD_STATUS_NEEDS_RECONCILIATION;
                $invoice->save();

                return null;
            }

            if ($invoice->fee_coin === null || $invoice->merchant_payout_coin === null) {
                throw new RuntimeException(
                    "Invoice [{$invoice->id}] has an incomplete locked settlement snapshot."
                );
            }

            return $invoice;
        });
    }

    private function markForwardingDone(int $invoiceId): void
    {
        DB::transaction(function () use ($invoiceId): void {
            /** @var Invoice $invoice */
            $invoice = Invoice::query()
                ->lockForUpdate()
                ->findOrFail($invoiceId);

            if (
                $invoice->status !== 'paid'
                || ! $invoice->hasRetryableForwardStatus()
                || $invoice->forward_attempt_uuid !== null
            ) {
                return;
            }

            $assetKey = $invoice->resolvedAssetKey();
            $recordedForwarded = $this->amounts->recordedForwardedCoin($invoice);

            if (
                $this->decimal->asset($recordedForwarded, $assetKey)
                    ->compareTo($this->decimal->asset($invoice->forwarded_coin, $assetKey)) > 0
            ) {
                $invoice->forwarded_coin = $recordedForwarded;
            }

            $invoice->forward_status = Invoice::FORWARD_STATUS_DONE;
            $invoice->save();
            $this->enqueueWebhook->enqueue(
                'invoice.forwarded',
                $invoice->loadMissing('merchant'),
                "invoice:{$invoice->id}:event:invoice.forwarded",
            );
        });
    }

    private function forwardUtxo(
        MerchantSettlementAttempt $attempt,
        SuperWallet $wallet,
        string $ownerToken,
    ): EvmPayoutResult {
        $sourceReference = "rpc-wallet:{$attempt->network_key}";
        $fingerprint = hash('sha256', json_encode([
            'network_key' => $attempt->network_key,
            'asset_key' => $attempt->asset_key,
            'source_reference' => $sourceReference,
            'destination_address' => $attempt->destination_address,
            'amount_coin' => (string) $attempt->amount_coin,
            'broadcast_reference' => $attempt->broadcast_reference,
        ], JSON_THROW_ON_ERROR));
        $rpc = Coin::rpc($attempt->asset_key);

        $this->attempts->markBroadcasting(
            attemptId: $attempt->id,
            sourceReference: $sourceReference,
            transactionFingerprint: $fingerprint,
            ownerToken: $ownerToken,
        );

        $txid = $rpc->sendToAddress(
            address: $attempt->destination_address,
            amount: (float) $this->decimal->format($attempt->amount_coin, $attempt->asset_key),
            feeRate: $wallet->fee_rate !== null ? (float) $wallet->fee_rate : null,
            reference: $attempt->broadcast_reference,
        );

        return new EvmPayoutResult(
            txHash: $txid,
            fromAddress: $sourceReference,
            toAddress: $attempt->destination_address,
            amountDecimal: (string) $attempt->amount_coin,
            meta: [
                'broadcast_reference' => $attempt->broadcast_reference,
                'transaction_fingerprint' => $fingerprint,
            ],
        );
    }

    private function forwardEvmNative(
        MerchantSettlementAttempt $attempt,
        Invoice $invoice,
        SuperWallet $wallet,
        string $ownerToken,
    ): EvmPayoutResult {
        $freshInvoice = Invoice::query()
            ->with(['merchant', 'paymentAddress'])
            ->findOrFail($invoice->id);
        $source = $this->evmSweepSourceResolver->resolveForInvoice($freshInvoice);
        $prepared = $this->evmPayoutSender->prepareNative(
            invoice: $freshInvoice,
            source: $source,
            destination: $wallet,
            amountDecimal: $this->formatAmountForEvm((string) $attempt->amount_coin, $attempt->asset_key),
        );

        $configuredChainId = (string) ($this->chains->get($attempt->network_key)['chain_id'] ?? '');
        if (
            $configuredChainId === ''
            || (string) $prepared->chainId !== $configuredChainId
            || $prepared->networkKey !== $attempt->network_key
            || $prepared->assetKey !== $attempt->asset_key
            || strtolower($prepared->source->address) !== strtolower($source->address)
            || strtolower($prepared->destinationAddress) !== strtolower($attempt->destination_address)
            || BigDecimal::of($prepared->amountDecimal)->compareTo(BigDecimal::zero()) <= 0
            || BigDecimal::of($prepared->amountDecimal)
                ->compareTo(BigDecimal::of((string) $attempt->amount_coin)) > 0
        ) {
            throw new RuntimeException(
                "Prepared EVM native payout does not match settlement attempt [{$attempt->attempt_uuid}]."
            );
        }

        $attempt = $this->attempts->recordPreparationContext(
            attemptId: $attempt->id,
            sourceAddress: strtolower($source->address),
            sourceReference: $source->keyRef,
            nonce: $prepared->nonce,
            chainId: (string) $prepared->chainId,
            broadcastBlockNumber: $prepared->broadcastBlockNumber,
            preparedAmount: $prepared->amountDecimal,
            atomicAmount: $prepared->amountAtomic,
            transactionFingerprint: $prepared->fingerprint(),
            metadata: [
                'source_derivation_path' => $source->derivationPath,
                'source_derivation_index' => $source->derivationIndex,
                'prepared_transaction' => $prepared->transaction,
            ],
            ownerToken: $ownerToken,
        );

        $this->attempts->markBroadcasting(
            attemptId: $attempt->id,
            sourceAddress: strtolower($source->address),
            sourceReference: $source->keyRef,
            nonce: $prepared->nonce,
            transactionFingerprint: $prepared->fingerprint(),
            metadata: [
                'source_derivation_path' => $source->derivationPath,
                'source_derivation_index' => $source->derivationIndex,
                'prepared_transaction' => $prepared->transaction,
            ],
            ownerToken: $ownerToken,
        );

        return $this->evmPayoutSender->broadcastNative($prepared);
    }

    private function forwardEvmErc20(
        MerchantSettlementAttempt $attempt,
        Invoice $invoice,
        SuperWallet $wallet,
        string $ownerToken,
    ): EvmPayoutResult {
        $freshInvoice = Invoice::query()
            ->with(['merchant', 'paymentAddress'])
            ->findOrFail($invoice->id);
        $source = $this->evmSweepSourceResolver->resolveForInvoice($freshInvoice);
        $amountDecimal = $this->formatAmountForEvm((string) $attempt->amount_coin, $attempt->asset_key);
        $asset = $this->assets->get($attempt->asset_key);
        $tokenContract = strtolower((string) ($asset['contract_address'] ?? ''));
        $fingerprint = hash('sha256', json_encode([
            'network_key' => $attempt->network_key,
            'asset_key' => $attempt->asset_key,
            'source_address' => strtolower($source->address),
            'destination_address' => $attempt->destination_address,
            'token_contract' => $tokenContract,
            'amount_decimal' => $amountDecimal,
        ], JSON_THROW_ON_ERROR));

        $attempt = $this->attempts->recordPreparationContext(
            attemptId: $attempt->id,
            sourceAddress: strtolower($source->address),
            sourceReference: $source->keyRef,
            tokenContract: $tokenContract,
            transactionFingerprint: $fingerprint,
            metadata: [
                'source_derivation_path' => $source->derivationPath,
                'source_derivation_index' => $source->derivationIndex,
            ],
            ownerToken: $ownerToken,
        );

        $topUpOutcome = $this->evmGasTopUpService->ensureTopUpForErc20Transfer(
            invoice: $freshInvoice,
            source: $source,
            destination: $wallet,
            amountDecimal: $amountDecimal,
        );

        if ($topUpOutcome->requiresDeferredPayout) {
            throw new EvmGasTopUpDeferredException($topUpOutcome);
        }

        $prepared = $this->evmTokenPayoutSender->prepareToken(
            invoice: $freshInvoice,
            source: $source,
            destination: $wallet,
            amountDecimal: $amountDecimal,
        );

        if (
            (string) $prepared->chainId !== (string) ($this->chains->get($attempt->network_key)['chain_id'] ?? '')
            || $prepared->networkKey !== $attempt->network_key
            || $prepared->assetKey !== $attempt->asset_key
            || strtolower($prepared->source->address) !== strtolower((string) $attempt->source_address)
            || strtolower($prepared->contractAddress) !== $tokenContract
            || strtolower($prepared->destinationAddress) !== strtolower($attempt->destination_address)
            || BigDecimal::of($prepared->amountDecimal)->compareTo(BigDecimal::of($amountDecimal)) !== 0
        ) {
            throw new RuntimeException(
                "Prepared ERC-20 payout does not match settlement attempt [{$attempt->attempt_uuid}]."
            );
        }

        $this->attempts->recordPreparationContext(
            attemptId: $attempt->id,
            sourceAddress: strtolower($source->address),
            sourceReference: $source->keyRef,
            nonce: $prepared->nonce,
            chainId: (string) $prepared->chainId,
            broadcastBlockNumber: $prepared->broadcastBlockNumber,
            preparedAmount: $prepared->amountDecimal,
            atomicAmount: $prepared->amountAtomic,
            tokenContract: $prepared->contractAddress,
            calldata: $prepared->calldata,
            transactionFingerprint: $prepared->fingerprint(),
            metadata: [
                'source_derivation_path' => $source->derivationPath,
                'source_derivation_index' => $source->derivationIndex,
                'prepared_transaction' => $prepared->transaction,
            ],
            ownerToken: $ownerToken,
        );

        $this->attempts->markBroadcasting(
            attemptId: $attempt->id,
            sourceAddress: strtolower($source->address),
            sourceReference: $source->keyRef,
            tokenContract: $tokenContract,
            nonce: $prepared->nonce,
            transactionFingerprint: $prepared->fingerprint(),
            metadata: [
                'source_derivation_path' => $source->derivationPath,
                'source_derivation_index' => $source->derivationIndex,
            ],
            ownerToken: $ownerToken,
        );

        return $this->evmTokenPayoutSender->broadcastToken($prepared);
    }

    private function formatAmountForEvm(string $amount, string $assetKey): string
    {
        return $this->decimal->format($amount, $assetKey);
    }

    private function isEvmTokenAsset(string $assetKey): bool
    {
        $asset = $this->assets->get($assetKey);

        return strtolower((string) ($asset['type'] ?? 'native')) === 'token'
            && strtolower((string) ($asset['token_standard'] ?? '')) === 'erc20';
    }
}
