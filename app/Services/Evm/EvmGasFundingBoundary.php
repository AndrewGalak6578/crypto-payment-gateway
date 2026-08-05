<?php

declare(strict_types=1);

namespace App\Services\Evm;

use App\Exceptions\EvmGasFundingAttemptNotAuthorizedException;
use App\Exceptions\ForwardingConfigurationException;
use App\Exceptions\ForwardingSwitchUnavailableException;
use App\Models\EvmGasFunding;
use App\Models\Invoice;
use App\Models\Merchant;
use App\Models\MerchantSettlementAttempt;
use App\Services\Forwarding\ForwardingGate;
use App\Services\Settlement\MerchantSettlementAttemptManager;
use Illuminate\Support\Facades\DB;

final readonly class EvmGasFundingBoundary
{
    public function __construct(
        private ForwardingGate $gate,
        private MerchantSettlementAttemptManager $attempts,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function begin(
        int $invoiceId,
        int $settlementAttemptId,
        string $settlementAttemptUuid,
        string $ownerToken,
        array $attributes,
    ): ?EvmGasFunding {
        $result = DB::transaction(function () use (
            $invoiceId,
            $settlementAttemptId,
            $settlementAttemptUuid,
            $ownerToken,
            $attributes,
        ): array {
            /** @var Invoice $invoice */
            $invoice = Invoice::query()->lockForUpdate()->findOrFail($invoiceId);

            /** @var Merchant $merchant */
            $merchant = Merchant::query()->lockForUpdate()->findOrFail($invoice->merchant_id);
            $invoice->setRelation('merchant', $merchant);

            /** @var MerchantSettlementAttempt|null $attempt */
            $attempt = MerchantSettlementAttempt::query()
                ->lockForUpdate()
                ->find($settlementAttemptId);

            $this->assertAuthorizedAttempt(
                attempt: $attempt,
                invoice: $invoice,
                merchant: $merchant,
                settlementAttemptUuid: $settlementAttemptUuid,
                ownerToken: $ownerToken,
                attributes: $attributes,
            );

            $state = $this->gate->inspectWithSharedLock();

            if (! $state->configValid) {
                $this->attempts->failReservedForGateLocked(
                    $attempt,
                    $invoice,
                    'forwarding_configuration_invalid_before_gas_funding',
                );

                return [null, 'configuration_invalid'];
            }

            if (! $state->dbAvailable) {
                throw new ForwardingSwitchUnavailableException(
                    'forwarding_switch_unavailable_before_gas_funding',
                );
            }

            if (! $state->effective()) {
                $this->attempts->failReservedForGateLocked(
                    $attempt,
                    $invoice,
                    'forwarding_disabled_before_gas_funding',
                );

                return [null, 'disabled'];
            }

            $now = now('UTC');
            $meta = is_array($attributes['meta'] ?? null) ? $attributes['meta'] : [];
            $funding = EvmGasFunding::query()->create(array_merge($attributes, [
                'invoice_id' => $invoice->id,
                'network_key' => $invoice->resolvedNetworkKey(),
                'asset_key' => $invoice->resolvedAssetKey(),
                'tx_hash' => null,
                'status' => 'broadcasting',
                'state' => EvmGasFunding::STATE_BROADCASTING,
                'retry_safe' => false,
                'reserved_at' => $now,
                'broadcasting_at' => $now,
                'next_reconciliation_at' => $now,
                'meta' => array_merge($meta, [
                    'settlement_attempt_id' => $attempt->id,
                    'settlement_attempt_uuid' => $attempt->attempt_uuid,
                ]),
            ]));

            return [$funding, null];
        });

        [$funding, $failure] = $result;

        if ($failure === 'configuration_invalid') {
            throw new ForwardingConfigurationException('forwarding_configuration_invalid_before_gas_funding');
        }

        return $funding;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function assertAuthorizedAttempt(
        ?MerchantSettlementAttempt $attempt,
        Invoice $invoice,
        Merchant $merchant,
        string $settlementAttemptUuid,
        string $ownerToken,
        array $attributes,
    ): void {
        $invoiceAssetKey = $invoice->resolvedAssetKey();
        $invoiceNetworkKey = $invoice->resolvedNetworkKey();
        $fundingAssetKey = strtolower(trim((string) ($attributes['asset_key'] ?? '')));
        $fundingNetworkKey = strtolower(trim((string) ($attributes['network_key'] ?? '')));

        if ($attempt === null) {
            $this->reject('settlement_attempt_missing_before_gas_funding');
        }

        if (
            (int) $attempt->invoice_id !== (int) $invoice->id
            || (int) $attempt->merchant_id !== (int) $merchant->id
            || $attempt->attempt_uuid !== $settlementAttemptUuid
        ) {
            $this->reject('settlement_attempt_identity_mismatch_before_gas_funding');
        }

        if ($invoice->forward_attempt_uuid !== $attempt->attempt_uuid) {
            $this->reject('settlement_attempt_replaced_before_gas_funding');
        }

        if ($attempt->state !== MerchantSettlementAttempt::STATE_RESERVED) {
            $this->reject('settlement_attempt_not_reserved_before_gas_funding');
        }

        if ($attempt->transfer_type !== MerchantSettlementAttempt::TRANSFER_ERC20) {
            $this->reject('settlement_attempt_not_erc20_before_gas_funding');
        }

        if (
            $ownerToken === ''
            || ! is_string($attempt->lease_owner_token)
            || ! hash_equals($attempt->lease_owner_token, $ownerToken)
        ) {
            $this->reject('settlement_attempt_owner_mismatch_before_gas_funding');
        }

        if ($attempt->lease_expires_at === null || ! $attempt->lease_expires_at->isFuture()) {
            $this->reject('settlement_attempt_lease_expired_before_gas_funding');
        }

        if (
            $attempt->asset_key !== $invoiceAssetKey
            || $attempt->network_key !== $invoiceNetworkKey
            || $fundingAssetKey !== $invoiceAssetKey
            || $fundingNetworkKey !== $invoiceNetworkKey
        ) {
            $this->reject('settlement_attempt_asset_network_mismatch_before_gas_funding');
        }
    }

    private function reject(string $reason): never
    {
        throw new EvmGasFundingAttemptNotAuthorizedException($reason);
    }
}
