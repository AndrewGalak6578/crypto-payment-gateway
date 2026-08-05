<?php

declare(strict_types=1);

namespace App\Services\Evm;

use App\Contracts\EvmAddressDeriverInterface;
use App\Contracts\EvmGasTopUpServiceInterface;
use App\Contracts\EvmTransactionSignerInterface;
use App\Data\EvmGasTopUpOutcome;
use App\Data\EvmSweepSource;
use App\Jobs\ForwardInvoiceJob;
use App\Jobs\ReconcileEvmGasFundingJob;
use App\Models\EvmGasFunding;
use App\Models\Invoice;
use App\Models\SuperWallet;
use App\Support\Assets\AssetRegistry;
use App\Support\Chains\ChainRegistry;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

final class EvmGasTopUpService implements EvmGasTopUpServiceInterface
{
    public function __construct(
        private readonly ChainRegistry $chains,
        private readonly AssetRegistry $assets,
        private readonly EvmAddressDeriverInterface $addressDeriver,
        private readonly EvmTransactionSignerInterface $signer,
        private readonly EvmTokenGasChecker $gasChecker,
        private readonly EvmRpcClientFactory $clients,
        private readonly EvmGasFundingBoundary $fundingBoundary,
    ) {}

    public function ensureTopUpForErc20Transfer(
        Invoice $invoice,
        int $settlementAttemptId,
        string $settlementAttemptUuid,
        string $ownerToken,
        EvmSweepSource $source,
        SuperWallet $destination,
        string $amountDecimal,
    ): EvmGasTopUpOutcome {
        $networkKey = $invoice->resolvedNetworkKey();
        $assetKey = $invoice->resolvedAssetKey();

        if ($networkKey !== $source->networkKey) {
            throw new RuntimeException(
                "Gas top-up source network [{$source->networkKey}] does not match invoice network [{$networkKey}]."
            );
        }

        if ($this->chains->family($networkKey) !== 'evm') {
            throw new RuntimeException("Network [{$networkKey}] is not EVM.");
        }

        $asset = $this->assets->get($assetKey);
        $assetType = strtolower((string) ($asset['type'] ?? 'native'));
        $tokenStandard = strtolower((string) ($asset['token_standard'] ?? ''));

        if ($assetType !== 'token' || $tokenStandard !== 'erc20') {
            return new EvmGasTopUpOutcome(
                status: 'not_applicable',
                requiresDeferredPayout: false,
            );
        }

        $contractAddress = strtolower(trim((string) ($asset['contract_address'] ?? '')));
        if (! preg_match('/^0x[a-f0-9]{40}$/', $contractAddress)) {
            throw new RuntimeException(
                "Asset [{$assetKey}] has invalid ERC-20 contract address [{$contractAddress}]."
            );
        }

        $destinationAddress = strtolower(trim((string) $destination->wallet));
        if (! preg_match('/^0x[a-f0-9]{40}$/', $destinationAddress)) {
            throw new RuntimeException(
                "Destination super wallet [{$destination->id}] has invalid wallet address."
            );
        }

        $sourceAddress = strtolower(trim($source->address));
        if (! preg_match('/^0x[a-f0-9]{40}$/', $sourceAddress)) {
            throw new RuntimeException("Source address [{$sourceAddress}] is invalid for ERC-20 gas top-up.");
        }

        $chain = $this->chains->get($networkKey);
        $rpcUrl = (string) ($chain['rpc_url'] ?? '');

        if ($rpcUrl === '') {
            throw new RuntimeException("Missing rpc_url for EVM network [{$networkKey}].");
        }

        $client = $this->clients->make($rpcUrl);
        $chainId = $client->chainId();
        if ((string) $chainId !== (string) ($chain['chain_id'] ?? '')) {
            throw new RuntimeException(
                "RPC chain ID [{$chainId}] does not match configured network [{$networkKey}]."
            );
        }
        $decimals = (int) ($asset['decimals'] ?? 18);
        $amountAtomic = $client->decimalStringToAtomic($amountDecimal, $decimals);

        if ($amountAtomic === '0') {
            throw new RuntimeException('ERC-20 gas top-up check requires non-zero token amount.');
        }

        $data = $client->encodeErc20TransferData($destinationAddress, $amountAtomic);
        $gasCheck = $this->gasChecker->checkForTransaction(
            client: $client,
            fromAddress: $sourceAddress,
            toAddress: $contractAddress,
            data: $data,
        );

        if ($gasCheck->hasEnoughGas) {
            return new EvmGasTopUpOutcome(
                status: 'sufficient',
                requiresDeferredPayout: false,
                meta: [
                    'native_balance_wei' => $gasCheck->nativeBalanceWei,
                    'estimated_gas_cost_wei' => $gasCheck->estimatedCostWei,
                    'gas_price_wei' => $gasCheck->gasPriceWei,
                    'gas_limit' => $gasCheck->gasLimit,
                ],
            );
        }

        if ((bool) config('payment_addresses.evm.gas_topup.enabled', false) === false) {
            throw new RuntimeException(
                "Insufficient native gas for ERC-20 payout from [{$sourceAddress}] and gas top-up is disabled."
            );
        }

        $retryDelaySeconds = $this->retryDelaySeconds();
        $gasStationSource = $this->resolveGasStationSource($networkKey);
        $this->assertGasStationIsolation(
            networkKey: $networkKey,
            depositSource: $source,
            depositAddress: $sourceAddress,
            gasStationSource: $gasStationSource,
        );
        $gasStationAddress = strtolower($gasStationSource->address);
        $lock = Cache::lock(
            $this->gasStationLockName($networkKey, $gasStationAddress),
            max(30, (int) config('payment_addresses.evm.gas_topup.account_lock_seconds', 180)),
        );

        try {
            $lock->block(max(1, (int) config('payment_addresses.evm.gas_topup.account_lock_wait_seconds', 10)));
        } catch (LockTimeoutException) {
            if ((string) config('queue.default', 'sync') !== 'sync') {
                ForwardInvoiceJob::dispatch($invoice->id)->delay(now('UTC')->addSeconds($retryDelaySeconds));
            }

            return new EvmGasTopUpOutcome(
                status: 'gas_station_busy',
                requiresDeferredPayout: true,
                gasStationAddress: $gasStationAddress,
                retryAfterSeconds: $retryDelaySeconds,
                meta: ['reason' => 'gas_station_account_lock_busy'],
            );
        }

        try {
            $pending = $this->findBlockingFundingForAccount($networkKey, $gasStationAddress);

            if ($pending !== null) {
                $this->scheduleReconciliation($pending);

                return new EvmGasTopUpOutcome(
                    status: 'awaiting_previous',
                    requiresDeferredPayout: true,
                    txHash: $pending->tx_hash,
                    fundedAmountWei: (string) $pending->amount_native_wei,
                    gasStationAddress: (string) $pending->source_address,
                    retryAfterSeconds: $retryDelaySeconds,
                    meta: array_merge(
                        is_array($pending->meta) ? $pending->meta : [],
                        [
                            'gas_funding_id' => $pending->id,
                            'target_address' => $pending->target_address,
                            'blocking_state' => $pending->state,
                        ],
                    ),
                );
            }

            // The first balance observation may predate a wait on the account lock.
            $gasCheck = $this->gasChecker->checkForTransaction(
                client: $client,
                fromAddress: $sourceAddress,
                toAddress: $contractAddress,
                data: $data,
            );
            if ($gasCheck->hasEnoughGas) {
                return new EvmGasTopUpOutcome(
                    status: 'sufficient',
                    requiresDeferredPayout: false,
                    meta: [
                        'native_balance_wei' => $gasCheck->nativeBalanceWei,
                        'estimated_gas_cost_wei' => $gasCheck->estimatedCostWei,
                        'gas_price_wei' => $gasCheck->gasPriceWei,
                        'gas_limit' => $gasCheck->gasLimit,
                        'rechecked_after_account_lock' => true,
                    ],
                );
            }

            $requiredBalanceWei = $this->requiredNativeBalanceWei($client, $networkKey, $gasCheck->estimatedCostWei);
            $neededWei = $client->subtractDecimalStrings($requiredBalanceWei, $gasCheck->nativeBalanceWei);

            if ($neededWei === '0') {
                return new EvmGasTopUpOutcome(
                    status: 'sufficient',
                    requiresDeferredPayout: false,
                    meta: [
                        'native_balance_wei' => $gasCheck->nativeBalanceWei,
                        'required_balance_wei' => $requiredBalanceWei,
                    ],
                );
            }

            $fundingGasPriceWei = $client->gasPriceWei();
            $fundingNonce = $client->getTransactionCount($gasStationAddress, 'pending');
            $fundingGasLimit = $client->estimateGas([
                'from' => $gasStationAddress,
                'to' => $sourceAddress,
                'value' => $client->decimalToHexQuantity($neededWei),
            ]);

            $fundingGasCostWei = $client->multiplyDecimalStrings($fundingGasLimit, $fundingGasPriceWei);
            $sponsorBalanceWei = $client->getBalanceWei($gasStationAddress, 'latest');
            $sponsorRequiredWei = $client->addDecimalStrings($neededWei, $fundingGasCostWei);

            if ($client->compareDecimalStrings($sponsorBalanceWei, $sponsorRequiredWei) < 0) {
                throw new RuntimeException(
                    "Gas station [{$gasStationAddress}] has insufficient native balance for top-up on [{$networkKey}]."
                );
            }

            $transaction = [
                'from' => $gasStationAddress,
                'to' => $sourceAddress,
                'value' => $client->decimalToHexQuantity($neededWei),
                'nonce' => $client->toHexQuantity($fundingNonce),
                'gas' => $client->decimalToHexQuantity($fundingGasLimit),
                'gasPrice' => $client->decimalToHexQuantity($fundingGasPriceWei),
            ];
            $fingerprint = hash('sha256', json_encode([
                'network_key' => $networkKey,
                'chain_id' => $chainId,
                'source_address' => $gasStationAddress,
                'nonce' => $fundingNonce,
                'target_address' => $sourceAddress,
                'amount_native_wei' => $neededWei,
                'transaction' => $transaction,
            ], JSON_THROW_ON_ERROR));

            $broadcastBlockNumber = $client->blockNumber();
            $funding = $this->fundingBoundary->begin(
                invoiceId: $invoice->id,
                settlementAttemptId: $settlementAttemptId,
                settlementAttemptUuid: $settlementAttemptUuid,
                ownerToken: $ownerToken,
                attributes: [
                    'funding_uuid' => (string) Str::uuid(),
                    'network_key' => $networkKey,
                    'asset_key' => $assetKey,
                    'source_address' => $gasStationAddress,
                    'target_address' => $sourceAddress,
                    'amount_native_wei' => $neededWei,
                    'chain_id' => (string) $chainId,
                    'nonce' => (string) $fundingNonce,
                    'required_confirmations' => max(1, $this->chains->confirmations($networkKey)),
                    'broadcast_block_number' => $broadcastBlockNumber,
                    'transaction_fingerprint' => $fingerprint,
                    'meta' => [
                        'reason' => 'insufficient_native_gas_for_erc20_payout',
                        'prepared_transaction' => $transaction,
                        'target_min_native_wei' => $this->targetMinWei($client, $networkKey),
                        'safety_buffer_wei' => $this->safetyBufferWei($client, $networkKey),
                        'required_native_balance_wei' => $requiredBalanceWei,
                        'native_balance_before_wei' => $gasCheck->nativeBalanceWei,
                        'estimated_erc20_gas_cost_wei' => $gasCheck->estimatedCostWei,
                        'erc20_gas_price_wei' => $gasCheck->gasPriceWei,
                        'erc20_gas_limit' => $gasCheck->gasLimit,
                        'gas_station_key_ref' => $gasStationSource->keyRef,
                        'gas_station_derivation_path' => $gasStationSource->derivationPath,
                        'gas_station_derivation_index' => $gasStationSource->derivationIndex,
                        'funding_tx_gas_price_wei' => $fundingGasPriceWei,
                        'funding_tx_gas_limit' => $fundingGasLimit,
                    ],
                ],
            );

            if ($funding === null) {
                return new EvmGasTopUpOutcome(
                    status: 'forwarding_disabled',
                    requiresDeferredPayout: true,
                    fundedAmountWei: $neededWei,
                    gasStationAddress: $gasStationAddress,
                    retryAfterSeconds: $retryDelaySeconds,
                    meta: [
                        'reason' => 'forwarding_disabled_before_gas_funding',
                        'target_address' => $sourceAddress,
                    ],
                );
            }

            try {
                $signed = $this->signer->signTransaction($networkKey, $gasStationSource, $transaction);
                $txHash = strtolower((string) ($signed['tx_hash'] ?? ''));
                if (! preg_match('/^0x[a-f0-9]{64}$/', $txHash)) {
                    throw new RuntimeException('EVM signer returned invalid tx hash for gas top-up transaction.');
                }
            } catch (Throwable $e) {
                $funding->forceFill([
                    'state' => EvmGasFunding::STATE_NEEDS_RECONCILIATION,
                    'status' => 'needs_reconciliation',
                    'retry_safe' => false,
                    'error_message' => $e->getMessage(),
                    'reconciliation_required_at' => now('UTC'),
                    'next_reconciliation_at' => now('UTC'),
                ])->save();
                $this->scheduleReconciliation($funding);

                return new EvmGasTopUpOutcome(
                    status: 'needs_reconciliation',
                    requiresDeferredPayout: true,
                    fundedAmountWei: $neededWei,
                    gasStationAddress: $gasStationAddress,
                    retryAfterSeconds: $retryDelaySeconds,
                    meta: [
                        'gas_funding_id' => $funding->id,
                        'target_address' => $sourceAddress,
                        'error' => $e->getMessage(),
                    ],
                );
            }

            $funding->forceFill([
                'tx_hash' => $txHash,
                'state' => EvmGasFunding::STATE_BROADCASTED,
                'status' => 'submitted',
                'broadcasted_at' => now('UTC'),
                'meta' => array_merge(
                    is_array($funding->meta) ? $funding->meta : [],
                    is_array($signed['meta'] ?? null) ? $signed['meta'] : [],
                ),
                'next_reconciliation_at' => now('UTC'),
            ])->save();
            $this->scheduleReconciliation($funding);

            return new EvmGasTopUpOutcome(
                status: 'funded',
                requiresDeferredPayout: true,
                txHash: $txHash,
                fundedAmountWei: $neededWei,
                gasStationAddress: $gasStationAddress,
                retryAfterSeconds: $retryDelaySeconds,
                meta: [
                    'gas_funding_id' => $funding->id,
                    'target_address' => $sourceAddress,
                    'required_native_balance_wei' => $requiredBalanceWei,
                    'native_balance_before_wei' => $gasCheck->nativeBalanceWei,
                ],
            );
        } finally {
            $lock->release();
        }
    }

    private function resolveGasStationSource(string $networkKey): EvmSweepSource
    {
        $keyRef = (string) config("payment_addresses.evm.gas_station_key_refs.{$networkKey}", '');

        if ($keyRef === '') {
            throw new RuntimeException(
                "Missing payment_addresses.evm.gas_station_key_refs[{$networkKey}] configuration."
            );
        }

        $depositDefaultKeyRef = (string) config("payment_addresses.evm.default_key_refs.{$networkKey}", '');
        if (
            $depositDefaultKeyRef !== ''
            && $this->normalizeConfigToken($depositDefaultKeyRef) === $this->normalizeConfigToken($keyRef)
        ) {
            throw new RuntimeException(
                "Invalid configuration for [{$networkKey}]: payment_addresses.evm.gas_station_key_refs[{$networkKey}] ".
                "must not match payment_addresses.evm.default_key_refs[{$networkKey}]."
            );
        }

        $pathTemplate = (string) config(
            'payment_addresses.evm.gas_station_derivation_path_template',
            "m/44'/60'/100'/0/%d"
        );

        $derived = $this->addressDeriver->derive(
            networkKey: $networkKey,
            keyRef: $keyRef,
            index: 0,
            pathTemplate: $pathTemplate,
        );

        $address = strtolower(trim($derived->address));
        if (! preg_match('/^0x[a-f0-9]{40}$/', $address)) {
            throw new RuntimeException("Derived gas station address [{$address}] is invalid.");
        }

        return new EvmSweepSource(
            networkKey: $networkKey,
            address: $address,
            keyRef: $derived->keyRef ?? $keyRef,
            derivationPath: $derived->derivationPath,
            derivationIndex: $derived->derivationIndex ?? 0,
            strategy: 'gas_station',
            meta: [
                'role' => 'erc20_gas_station',
            ],
        );
    }

    private function assertGasStationIsolation(
        string $networkKey,
        EvmSweepSource $depositSource,
        string $depositAddress,
        EvmSweepSource $gasStationSource,
    ): void {
        $normalizedDepositAddress = strtolower(trim($depositAddress));
        $normalizedGasStationAddress = strtolower(trim($gasStationSource->address));

        if ($normalizedGasStationAddress === $normalizedDepositAddress) {
            throw new RuntimeException(
                "Invalid configuration for [{$networkKey}]: gas station address [{$normalizedGasStationAddress}] ".
                "matches deposit source address [{$normalizedDepositAddress}]."
            );
        }

        $depositKeyRef = $this->normalizeConfigToken($depositSource->keyRef);
        $gasStationKeyRef = $this->normalizeConfigToken($gasStationSource->keyRef);
        if ($depositKeyRef !== '' && $gasStationKeyRef !== '' && $depositKeyRef === $gasStationKeyRef) {
            throw new RuntimeException(
                "Invalid configuration for [{$networkKey}]: gas station key_ref [{$gasStationSource->keyRef}] ".
                "matches deposit source key_ref [{$depositSource->keyRef}]. Use a dedicated gas station key_ref."
            );
        }

        $depositPath = $this->normalizeConfigToken((string) $depositSource->derivationPath);
        $gasStationPath = $this->normalizeConfigToken((string) $gasStationSource->derivationPath);
        if ($depositPath !== '' && $gasStationPath !== '' && $depositPath === $gasStationPath) {
            throw new RuntimeException(
                "Invalid configuration for [{$networkKey}]: gas station derivation path [{$gasStationSource->derivationPath}] ".
                "matches deposit source derivation path [{$depositSource->derivationPath}]."
            );
        }
    }

    private function findBlockingFundingForAccount(string $networkKey, string $sourceAddress): ?EvmGasFunding
    {
        return EvmGasFunding::query()
            ->where('network_key', $networkKey)
            ->where('source_address', strtolower($sourceAddress))
            ->whereIn('state', [
                EvmGasFunding::STATE_RESERVED,
                EvmGasFunding::STATE_BROADCASTING,
                EvmGasFunding::STATE_BROADCASTED,
                EvmGasFunding::STATE_NEEDS_RECONCILIATION,
            ])
            ->latest('id')
            ->first();
    }

    private function scheduleReconciliation(EvmGasFunding $funding): void
    {
        if ((string) config('queue.default', 'sync') === 'sync') {
            return;
        }

        ReconcileEvmGasFundingJob::dispatch($funding->id)
            ->delay(now('UTC')->addSeconds(5));
    }

    private function gasStationLockName(string $networkKey, string $sourceAddress): string
    {
        return 'evm-gas-station:'.hash('sha256', strtolower($networkKey).':'.strtolower($sourceAddress));
    }

    private function requiredNativeBalanceWei(
        EvmRpcClient $client,
        string $networkKey,
        string $estimatedCostWei,
    ): string {
        $required = $client->addDecimalStrings($estimatedCostWei, $this->safetyBufferWei($client, $networkKey));
        $targetMinWei = $this->targetMinWei($client, $networkKey);

        if ($client->compareDecimalStrings($targetMinWei, $required) > 0) {
            return $targetMinWei;
        }

        return $required;
    }

    private function targetMinWei(EvmRpcClient $client, string $networkKey): string
    {
        $configuredWei = trim((string) config('payment_addresses.evm.gas_topup.target_min_native_wei', ''));
        if ($this->isUnsignedInteger($configuredWei)) {
            return ltrim($configuredWei, '0') ?: '0';
        }

        $configuredDecimal = trim((string) config('payment_addresses.evm.gas_topup.target_min_native_decimal', '0'));
        if ($this->isDecimalString($configuredDecimal)) {
            return $client->decimalStringToAtomic($configuredDecimal, $this->nativeDecimalsForNetwork($networkKey));
        }

        return '0';
    }

    private function safetyBufferWei(EvmRpcClient $client, string $networkKey): string
    {
        $configuredWei = trim((string) config('payment_addresses.evm.gas_topup.safety_buffer_wei', ''));
        if ($this->isUnsignedInteger($configuredWei)) {
            return ltrim($configuredWei, '0') ?: '0';
        }

        $configuredDecimal = trim((string) config('payment_addresses.evm.gas_topup.safety_buffer_decimal', '0'));
        if ($this->isDecimalString($configuredDecimal)) {
            return $client->decimalStringToAtomic($configuredDecimal, $this->nativeDecimalsForNetwork($networkKey));
        }

        return '0';
    }

    private function nativeDecimalsForNetwork(string $networkKey): int
    {
        $assets = config('assets', []);

        if (! is_array($assets)) {
            return 18;
        }

        foreach ($assets as $asset) {
            if (! is_array($asset)) {
                continue;
            }

            if (strtolower((string) ($asset['network'] ?? '')) !== $networkKey) {
                continue;
            }

            if (strtolower((string) ($asset['type'] ?? 'native')) !== 'native') {
                continue;
            }

            $decimals = (int) ($asset['decimals'] ?? 18);

            return $decimals > 0 ? $decimals : 18;
        }

        return 18;
    }

    private function isDecimalString(string $value): bool
    {
        return preg_match('/^\d+(?:\.\d+)?$/', $value) === 1;
    }

    private function isUnsignedInteger(string $value): bool
    {
        return preg_match('/^\d+$/', $value) === 1;
    }

    private function normalizeConfigToken(string $value): string
    {
        return strtolower(trim($value));
    }

    private function retryDelaySeconds(): int
    {
        return max(5, (int) config('payment_addresses.evm.gas_topup.retry_delay_seconds', 30));
    }
}
