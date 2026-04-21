<?php
declare(strict_types=1);

namespace App\Services\Evm;

use App\Contracts\EvmAddressDeriverInterface;
use App\Contracts\EvmGasTopUpServiceInterface;
use App\Contracts\EvmTransactionSignerInterface;
use App\Data\EvmGasTopUpOutcome;
use App\Data\EvmSweepSource;
use App\Models\EvmGasFunding;
use App\Models\Invoice;
use App\Models\SuperWallet;
use App\Support\Assets\AssetRegistry;
use App\Support\Chains\ChainRegistry;
use RuntimeException;

final class EvmGasTopUpService implements EvmGasTopUpServiceInterface
{
    public function __construct(
        private readonly ChainRegistry $chains,
        private readonly AssetRegistry $assets,
        private readonly EvmAddressDeriverInterface $addressDeriver,
        private readonly EvmTransactionSignerInterface $signer,
        private readonly EvmTokenGasChecker $gasChecker,
    )
    {
    }

    public function ensureTopUpForErc20Transfer(
        Invoice $invoice,
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
        $assetType = strtolower((string)($asset['type'] ?? 'native'));
        $tokenStandard = strtolower((string)($asset['token_standard'] ?? ''));

        if ($assetType !== 'token' || $tokenStandard !== 'erc20') {
            return new EvmGasTopUpOutcome(
                status: 'not_applicable',
                requiresDeferredPayout: false,
            );
        }

        $contractAddress = strtolower(trim((string)($asset['contract_address'] ?? '')));
        if (!preg_match('/^0x[a-f0-9]{40}$/', $contractAddress)) {
            throw new RuntimeException(
                "Asset [{$assetKey}] has invalid ERC-20 contract address [{$contractAddress}]."
            );
        }

        $destinationAddress = strtolower(trim((string)$destination->wallet));
        if (!preg_match('/^0x[a-f0-9]{40}$/', $destinationAddress)) {
            throw new RuntimeException(
                "Destination super wallet [{$destination->id}] has invalid wallet address."
            );
        }

        $sourceAddress = strtolower(trim($source->address));
        if (!preg_match('/^0x[a-f0-9]{40}$/', $sourceAddress)) {
            throw new RuntimeException("Source address [{$sourceAddress}] is invalid for ERC-20 gas top-up.");
        }

        $chain = $this->chains->get($networkKey);
        $rpcUrl = (string)($chain['rpc_url'] ?? '');

        if ($rpcUrl === '') {
            throw new RuntimeException("Missing rpc_url for EVM network [{$networkKey}].");
        }

        $client = new EvmRpcClient($rpcUrl);
        $decimals = (int)($asset['decimals'] ?? 18);
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

        if ((bool)config('payment_addresses.evm.gas_topup.enabled', false) === false) {
            throw new RuntimeException(
                "Insufficient native gas for ERC-20 payout from [{$sourceAddress}] and gas top-up is disabled."
            );
        }

        $pending = $this->findPendingFunding($networkKey, $sourceAddress);
        $retryDelaySeconds = $this->retryDelaySeconds();

        if ($pending !== null) {
            return new EvmGasTopUpOutcome(
                status: 'awaiting_previous',
                requiresDeferredPayout: true,
                txHash: (string)$pending->tx_hash,
                fundedAmountWei: (string)$pending->amount_native_wei,
                gasStationAddress: (string)$pending->source_address,
                retryAfterSeconds: $retryDelaySeconds,
                meta: array_merge(
                    is_array($pending->meta) ? $pending->meta : [],
                    [
                        'gas_funding_id' => $pending->id,
                        'target_address' => $sourceAddress,
                    ],
                ),
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

        $gasStationSource = $this->resolveGasStationSource($networkKey);
        $this->assertGasStationIsolation(
            networkKey: $networkKey,
            depositSource: $source,
            depositAddress: $sourceAddress,
            gasStationSource: $gasStationSource,
        );

        $gasStationAddress = strtolower($gasStationSource->address);
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

        $signed = $this->signer->signTransaction($networkKey, $gasStationSource, [
            'from' => $gasStationAddress,
            'to' => $sourceAddress,
            'value' => $client->decimalToHexQuantity($neededWei),
            'nonce' => $client->toHexQuantity($fundingNonce),
            'gas' => $client->decimalToHexQuantity($fundingGasLimit),
            'gasPrice' => $client->decimalToHexQuantity($fundingGasPriceWei),
        ]);

        $txHash = strtolower((string)($signed['tx_hash'] ?? ''));
        if (!preg_match('/^0x[a-f0-9]{64}$/', $txHash)) {
            throw new RuntimeException('EVM signer returned invalid tx hash for gas top-up transaction.');
        }

        $funding = EvmGasFunding::query()->create([
            'invoice_id' => $invoice->id,
            'network_key' => $networkKey,
            'asset_key' => $assetKey,
            'source_address' => $gasStationAddress,
            'target_address' => $sourceAddress,
            'amount_native_wei' => $neededWei,
            'tx_hash' => $txHash,
            'status' => 'submitted',
            'meta' => array_merge(
                [
                    'reason' => 'insufficient_native_gas_for_erc20_payout',
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
                is_array($signed['meta'] ?? null) ? $signed['meta'] : [],
            ),
        ]);

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
    }

    private function resolveGasStationSource(string $networkKey): EvmSweepSource
    {
        $keyRef = (string)config("payment_addresses.evm.gas_station_key_refs.{$networkKey}", '');

        if ($keyRef === '') {
            throw new RuntimeException(
                "Missing payment_addresses.evm.gas_station_key_refs[{$networkKey}] configuration."
            );
        }

        $depositDefaultKeyRef = (string)config("payment_addresses.evm.default_key_refs.{$networkKey}", '');
        if (
            $depositDefaultKeyRef !== ''
            && $this->normalizeConfigToken($depositDefaultKeyRef) === $this->normalizeConfigToken($keyRef)
        ) {
            throw new RuntimeException(
                "Invalid configuration for [{$networkKey}]: payment_addresses.evm.gas_station_key_refs[{$networkKey}] " .
                "must not match payment_addresses.evm.default_key_refs[{$networkKey}]."
            );
        }

        $pathTemplate = (string)config(
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
        if (!preg_match('/^0x[a-f0-9]{40}$/', $address)) {
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
                "Invalid configuration for [{$networkKey}]: gas station address [{$normalizedGasStationAddress}] " .
                "matches deposit source address [{$normalizedDepositAddress}]."
            );
        }

        $depositKeyRef = $this->normalizeConfigToken($depositSource->keyRef);
        $gasStationKeyRef = $this->normalizeConfigToken($gasStationSource->keyRef);
        if ($depositKeyRef !== '' && $gasStationKeyRef !== '' && $depositKeyRef === $gasStationKeyRef) {
            throw new RuntimeException(
                "Invalid configuration for [{$networkKey}]: gas station key_ref [{$gasStationSource->keyRef}] " .
                "matches deposit source key_ref [{$depositSource->keyRef}]. Use a dedicated gas station key_ref."
            );
        }

        $depositPath = $this->normalizeConfigToken((string)$depositSource->derivationPath);
        $gasStationPath = $this->normalizeConfigToken((string)$gasStationSource->derivationPath);
        if ($depositPath !== '' && $gasStationPath !== '' && $depositPath === $gasStationPath) {
            throw new RuntimeException(
                "Invalid configuration for [{$networkKey}]: gas station derivation path [{$gasStationSource->derivationPath}] " .
                "matches deposit source derivation path [{$depositSource->derivationPath}]."
            );
        }
    }

    private function findPendingFunding(string $networkKey, string $targetAddress): ?EvmGasFunding
    {
        $cooldownSeconds = max(0, (int)config('payment_addresses.evm.gas_topup.pending_cooldown_seconds', 45));
        $query = EvmGasFunding::query()
            ->where('network_key', $networkKey)
            ->where('target_address', strtolower($targetAddress))
            ->where('status', 'submitted')
            ->latest('id');

        if ($cooldownSeconds > 0) {
            $query->where('created_at', '>=', now('UTC')->subSeconds($cooldownSeconds));
        }

        return $query->first();
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
        $configuredWei = trim((string)config('payment_addresses.evm.gas_topup.target_min_native_wei', ''));
        if ($this->isUnsignedInteger($configuredWei)) {
            return ltrim($configuredWei, '0') ?: '0';
        }

        $configuredDecimal = trim((string)config('payment_addresses.evm.gas_topup.target_min_native_decimal', '0'));
        if ($this->isDecimalString($configuredDecimal)) {
            return $client->decimalStringToAtomic($configuredDecimal, $this->nativeDecimalsForNetwork($networkKey));
        }

        return '0';
    }

    private function safetyBufferWei(EvmRpcClient $client, string $networkKey): string
    {
        $configuredWei = trim((string)config('payment_addresses.evm.gas_topup.safety_buffer_wei', ''));
        if ($this->isUnsignedInteger($configuredWei)) {
            return ltrim($configuredWei, '0') ?: '0';
        }

        $configuredDecimal = trim((string)config('payment_addresses.evm.gas_topup.safety_buffer_decimal', '0'));
        if ($this->isDecimalString($configuredDecimal)) {
            return $client->decimalStringToAtomic($configuredDecimal, $this->nativeDecimalsForNetwork($networkKey));
        }

        return '0';
    }

    private function nativeDecimalsForNetwork(string $networkKey): int
    {
        $assets = config('assets', []);

        if (!is_array($assets)) {
            return 18;
        }

        foreach ($assets as $asset) {
            if (!is_array($asset)) {
                continue;
            }

            if (strtolower((string)($asset['network'] ?? '')) !== $networkKey) {
                continue;
            }

            if (strtolower((string)($asset['type'] ?? 'native')) !== 'native') {
                continue;
            }

            $decimals = (int)($asset['decimals'] ?? 18);

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
        return max(5, (int)config('payment_addresses.evm.gas_topup.retry_delay_seconds', 30));
    }
}
