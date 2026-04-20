<?php
declare(strict_types=1);

namespace App\Services;

use App\Contracts\EvmPayoutSenderInterface;
use App\Contracts\EvmSweepSourceResolverInterface;
use App\Contracts\EvmTokenPayoutSenderInterface;
use App\Exceptions\EvmGasTopUpDeferredException;
use App\Jobs\ForwardInvoiceJob;
use App\Models\Invoice;
use App\Models\SuperWallet;
use App\Services\Evm\EvmGasTopUpService;
use App\Services\Settlement\MerchantBalanceCreditor;
use App\Services\Settlement\SuperWalletResolver;
use App\Services\Webhooks\EnqueueInvoiceWebhook;
use App\Support\Assets\AssetRegistry;
use App\Support\Chains\ChainRegistry;
use App\Support\Coin;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;
use RuntimeException;

/**
 * Handles merchant settlement after invoice payment.
 *
 * Settlement strategy:
 * - If a destination wallet exists, transfer only the merchant net amount.
 * - If no destination wallet exists, credit merchant internal balance.
 */
final class InvoiceForwarder
{
    public function __construct(
        private readonly EnqueueInvoiceWebhook           $enqueueWebhook,
        private readonly SuperWalletResolver             $walletResolver,
        private readonly MerchantBalanceCreditor         $balanceCreditor,
        private readonly AssetRegistry                   $assets,
        private readonly ChainRegistry                   $chains,
        private readonly EvmSweepSourceResolverInterface $evmSweepSourceResolver,
        private readonly EvmPayoutSenderInterface        $evmPayoutSender,
        private readonly EvmTokenPayoutSenderInterface   $evmTokenPayoutSender,
    )
    {
    }

    /**
     * Attempts to settle a paid invoice.
     *
     * @param int $invoiceId Internal invoice identifier.
     * @throws \Throwable Re-thrown after marking forwarding attempt as failed.
     */
    public function forward(int $invoiceId): void
    {
        /** @var Invoice $invoice */
        $invoice = Invoice::query()
            ->with(['merchant', 'paymentAddress'])
            ->findOrFail($invoiceId);

        $assetKey = $invoice->resolvedAssetKey();
        $networkKey = $invoice->resolvedNetworkKey();

        $wallet = $this->walletResolver->resolveByAsset(
            merchant: $invoice->merchant,
            assetKey: $assetKey,
            networkKey: $networkKey,
        );

        if (!$wallet) {
            $this->balanceCreditor->credit($invoiceId);

            $fresh = Invoice::query()->with('merchant')->find($invoiceId);
            if ($fresh) {
                $this->enqueueWebhook->enqueue('invoice.forwarded', $fresh);
            }

            return;
        }

        $plan = $this->reserveForwarding($invoiceId, $wallet);

        if ($plan === null) {
            return;
        }

        try {
            $family = $this->chains->family($plan['network_key']);

            $forwardResult = match ($family) {
                'utxo' => [
                    'txid' => $this->forwardUtxo($plan),
                    'amount' => $plan['amount'],
                ],
                'evm' => $this->isEvmTokenAsset($plan['asset_key'])
                    ? $this->forwardEvmErc20($invoice, $wallet, $plan)
                    : $this->forwardEvmNative($invoice, $wallet, $plan),
                default => throw new RuntimeException(
                    "Unsupported forwarding family [{$family}] for network [{$plan['network_key']}]."
                )
            };

            $this->markForwarded(
                invoiceId: $invoiceId,
                attemptUuid: $plan['attempt_uuid'],
                amount: (float)$forwardResult['amount'],
                txid: (string)$forwardResult['txid'],
            );

            $fresh = Invoice::query()->with('merchant')->findOrFail($invoiceId);

            if ($fresh) {
                $this->enqueueWebhook->enqueue('invoice.forwarded', $fresh);
            }
        } catch (EvmGasTopUpDeferredException $e) {
            $this->markDeferred($invoiceId, $plan['attempt_uuid']);

            if ((string)config('queue.default', 'database') !== 'sync') {
                ForwardInvoiceJob::dispatch($invoiceId)
                    ->delay(now('UTC')->addSeconds($e->outcome->retryAfterSeconds));
            }
        } catch (Throwable $e) {
            $this->markFailed($invoiceId, $plan['attempt_uuid']);
            report($e);
            throw $e;
        }
    }

    /**
     * Reserves a forwarding attempt and returns immutable transfer plan.
     *
     * @param int $invoiceId Internal invoice identifier.
     * @param SuperWallet $wallet Resolved destination wallet.
     * @return array{
     *     attempt_uuid: string,
     *     asset_key: string,
     *     network_key: string,
     *     wallet: string,
     *     fee_rate: float|null,
     *     amount: float
     * }|null
     */
    private function reserveForwarding(int $invoiceId, SuperWallet $wallet): ?array
    {
        return DB::transaction(function () use ($invoiceId, $wallet): ?array {
            /** @var Invoice $invoice */
            $invoice = Invoice::query()
                ->lockForUpdate()
                ->findOrFail($invoiceId);

            if ($invoice->status !== 'paid') {
                return null;
            }

            if ($invoice->forward_attempt_uuid !== null) {
                return null;
            }

            $assetKey = $invoice->resolvedAssetKey();
            $scale = $this->assets->settlementScale($assetKey);
            $epsilon = $this->assets->epsilon($assetKey);

            $confirmed = $this->norm((float)($invoice->received_conf_coin ?? 0), $scale);
            $forwarded = $this->norm((float)($invoice->forwarded_coin ?? 0), $scale);
            $feePercent = (float)($invoice->merchant->fee_percent ?? 0.0);
            // Merchant receives net amount after fee retention by the gateway.
            $targetNet = $this->norm($confirmed - ($confirmed * ($feePercent / 100)), $scale);
            $targetNet = max(0.0, $targetNet);
            $amount = $this->norm($targetNet - $forwarded, $scale);
            $amount = max(0.0, $amount);

            if ($amount <= $epsilon) {
                $invoice->forward_status = 'done';
                $invoice->save();

                return null;
            }

            $min = $this->norm((float)config("forwarding.assets.{$assetKey}.min", 0), $scale);

            if ($amount < $min) {
                $invoice->forward_status = $forwarded > $epsilon ? 'partial' : 'none';
                $invoice->save();

                return null;
            }

            $attemptUuid = (string)Str::uuid();

            $invoice->forward_status = 'processing';
            $invoice->forward_attempt_uuid = $attemptUuid;
            $invoice->forwarding_coin = $amount;
            $invoice->forwarding_started_at = now('UTC');
            $invoice->save();

            return [
                'attempt_uuid' => $attemptUuid,
                'asset_key' => $assetKey,
                'network_key' => $invoice->resolvedNetworkKey(),
                'wallet' => $wallet->wallet,
                'fee_rate' => $wallet->fee_rate !== null ? (float)$wallet->fee_rate : null,
                'amount' => $amount,
            ];
        });
    }

    /**
     * Finalizes successful forwarding attempt and updates invoice settlement fields.
     *
     * @param int $invoiceId Internal invoice identifier.
     * @param string $attemptUuid Forwarding attempt UUID reserved before RPC send.
     * @param float $amount Settled amount that was sent to destination wallet.
     * @param string $txid Transaction identifier returned by chain RPC.
     */
    private function markForwarded(int $invoiceId, string $attemptUuid, float $amount, string $txid): void
    {
        DB::transaction(function () use ($invoiceId, $attemptUuid, $amount, $txid): void {
            /** @var Invoice $invoice */
            $invoice = Invoice::query()
                ->lockForUpdate()
                ->findOrFail($invoiceId);

            if ($invoice->forward_attempt_uuid !== $attemptUuid) {
                return;
            }

            $scale = $this->assets->settlementScale($invoice->resolvedAssetKey());
            $epsilon = $this->assets->epsilon($invoice->resolvedAssetKey());

            $txids = $invoice->forward_txids ?? [];
            $txids[] = $txid;

            $newForwarded = $this->norm(
                (float)($invoice->forwarded_coin ?? 0) + $amount,
                $scale
            );

            $confirmed = $this->norm((float)($invoice->received_conf_coin ?? 0), $scale);
            $feePercent = (float)($invoice->merchant->fee_percent ?? 0.0);
            $targetNet = $this->norm($confirmed - ($confirmed * ($feePercent / 100)), $scale);
            $targetNet = max(0.0, $targetNet);
            $rest = $this->norm($targetNet - $newForwarded, $scale);

            $invoice->forwarded_coin = $newForwarded;
            $invoice->forward_txids = $txids;
            $invoice->last_forwarded_at = now('UTC');
            $invoice->forward_status = $rest <= $epsilon ? 'done' : 'partial';

            $invoice->forward_attempt_uuid = null;
            $invoice->forwarding_coin = null;
            $invoice->forwarding_started_at = null;

            $invoice->save();
        });
    }

    /**
     * Marks reserved forwarding attempt as failed and clears processing fields.
     *
     * @param int $invoiceId Internal invoice identifier.
     * @param string $attemptUuid Forwarding attempt UUID.
     */
    private function markFailed(int $invoiceId, string $attemptUuid): void
    {
        DB::transaction(function () use ($invoiceId, $attemptUuid): void {
            /** @var Invoice $invoice */
            $invoice = Invoice::query()
                ->lockForUpdate()
                ->findOrFail($invoiceId);

            if ($invoice->forward_attempt_uuid !== $attemptUuid) {
                return;
            }

            $invoice->forward_status = 'failed';
            $invoice->forward_attempt_uuid = null;
            $invoice->forwarding_coin = null;
            $invoice->forwarding_started_at = null;
            $invoice->save();
        });
    }

    private function markDeferred(int $invoiceId, string $attemptUuid): void
    {
        DB::transaction(function () use ($invoiceId, $attemptUuid): void {
            /** @var Invoice $invoice */
            $invoice = Invoice::query()
                ->lockForUpdate()
                ->findOrFail($invoiceId);

            if ($invoice->forward_attempt_uuid !== $attemptUuid) {
                return;
            }

            $assetKey = $invoice->resolvedAssetKey();
            $scale = $this->assets->settlementScale($assetKey);
            $epsilon = $this->assets->epsilon($assetKey);
            $forwarded = $this->norm((float)($invoice->forwarded_coin ?? 0), $scale);

            $invoice->forward_status = $forwarded > $epsilon ? 'partial' : 'none';
            $invoice->forward_attempt_uuid = null;
            $invoice->forwarding_coin = null;
            $invoice->forwarding_started_at = null;
            $invoice->save();
        });
    }


    /**
     * Rounds coin values to chain-specific precision.
     *
     * @param float $value Value to normalize.
     * @param int $scale Number of decimal places for target coin.
     */
    private function norm(float $value, int $scale): float
    {
        return round($value, $scale);
    }

    /**
     * Forwarding logic for UTXO chains (like BTC)
     * basically just sendToAddress()
     *
     * @param array $plan
     * @return string
     */
    private function forwardUtxo(array $plan): string
    {
        $rpc = Coin::rpc($plan['asset_key']);

        return $rpc->sendToAddress(
            address: $plan['wallet'],
            amount: $plan['amount'],
            feeRate: $plan['fee_rate'],
        );
    }

    /**
     * Forwarding logic for EVM based chains (like Ethereum)
     *
     * @param Invoice $invoice
     * @param SuperWallet $wallet
     * @param array $plan
     * @return array
     */
    private function forwardEvmNative(Invoice $invoice, SuperWallet $wallet, array $plan): array
    {
        $freshInvoice = Invoice::query()
            ->with(['merchant', 'paymentAddress'])
            ->findOrFail($invoice->id);

        $source = $this->evmSweepSourceResolver->resolveForInvoice($freshInvoice);

        $result = $this->evmPayoutSender->sendNative(
            invoice: $freshInvoice,
            source: $source,
            destination: $wallet,
            amountDecimal: $this->formatAmountForEvm($plan['amount'], $plan['asset_key']),
        );

        return [
            'txid' => $result->txHash,
            'amount' => (float)$result->amountDecimal
        ]; //0x3c44cdddb6a900fa2b585dd299e03d12fa4293bc
    }

    /**
     * Forwarding logic for EVM ERC-20 tokens
     *
     * @param Invoice $invoice
     * @param SuperWallet $wallet
     * @param array $plan
     * @return array
     */
    private function forwardEvmErc20(Invoice $invoice, SuperWallet $wallet, array $plan): array
    {
        $freshInvoice = Invoice::query()
            ->with(['merchant', 'paymentAddress'])
            ->findOrFail($invoice->id);

        $source = $this->evmSweepSourceResolver->resolveForInvoice($freshInvoice);
        $amountDecimal = $this->formatAmountForEvm($plan['amount'], $plan['asset_key']);

        $topUpOutcome = app(EvmGasTopUpService::class)->ensureTopUpForErc20Transfer(
            invoice: $freshInvoice,
            source: $source,
            destination: $wallet,
            amountDecimal: $amountDecimal,
        );

        if ($topUpOutcome->requiresDeferredPayout) {
            throw new EvmGasTopUpDeferredException($topUpOutcome);
        }

        $result = $this->evmTokenPayoutSender->sendToken(
            invoice: $freshInvoice,
            source: $source,
            destination: $wallet,
            amountDecimal: $amountDecimal,
        );

        return [
            'txid' => $result->txHash,
            'amount' => (float) $result->amountDecimal,
        ];
    }

    private function formatAmountForEvm(float $amount, string $assetKey): string
    {
        $scale = $this->assets->settlementScale($assetKey);

        return number_format($amount, $scale, '.', '');
    }

    private function isEvmTokenAsset(string $assetKey): bool
    {
        $asset = $this->assets->get($assetKey);

        return strtolower((string)($asset['type'] ?? 'native')) === 'token'
            && strtolower((string)($asset['token_standard'] ?? '')) === 'erc20';
    }

}
