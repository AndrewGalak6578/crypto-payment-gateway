<?php
declare(strict_types=1);

namespace App\Services\Evm;

use App\Contracts\EvmPayoutSenderInterface;
use App\Contracts\EvmTransactionSignerInterface;
use App\Data\EvmPayoutResult;
use App\Data\EvmSweepSource;
use App\Models\Invoice;
use App\Models\SuperWallet;
use App\Support\Assets\AssetRegistry;
use App\Support\Chains\ChainRegistry;
use RuntimeException;

final class EvmNativePayoutSender implements EvmPayoutSenderInterface
{
    public function __construct(
        private readonly ChainRegistry $chains,
        private readonly AssetRegistry $assets,
        private readonly EvmTransactionSignerInterface $signer,
    )
    {
    }

    public function sendNative(
        Invoice $invoice,
        EvmSweepSource $source,
        SuperWallet $destination,
        string $amountDecimal
    ): EvmPayoutResult {
        $networkKey = $invoice->resolvedNetworkKey();
        $assetKey = $invoice->resolvedAssetKey();

        if ($networkKey !== $source->networkKey) {
            throw new RuntimeException(
                "Source network [{$source->networkKey}] does not match invoice network [{$networkKey}]."
            );
        }

        if ($this->chains->family($networkKey) !== 'evm') {
            throw new RuntimeException("Network [{$networkKey}] is not EVM.");
        }

        $destinationAddress = strtolower((string) $destination->wallet);
        if ($destinationAddress === '') {
            throw new RuntimeException(
                "Destination super wallet [{$destination->id}] has empty wallet address."
            );
        }

        $chain = $this->chains->get($networkKey);
        $rpcUrl = (string) ($chain['rpc_url'] ?? '');

        if ($rpcUrl === '') {
            throw new RuntimeException("Missing rpc_url for EVM network [{$networkKey}]");
        }

        $client = new EvmRpcClient($rpcUrl);
        $decimals = (int) ($this->assets->get($assetKey)['decimals'] ?? 18);

        $requestedAtomic = $client->decimalStringToAtomic($amountDecimal, $decimals);
        if ($requestedAtomic === '0') {
            throw new RuntimeException('EVM payout amount is zero.');
        }

        $sourceAddress = strtolower($source->address);
        $balanceAtomic = $client->getBalanceWei($sourceAddress, 'latest');
        $nonce = $client->getTransactionCount($sourceAddress, 'pending');
        $gasPriceWei = $client->gasPriceWei();

        $estimatePayload = [
            'from' => $sourceAddress,
            'to' => $destinationAddress,
            'value' => $client->decimalToHexQuantity($requestedAtomic),
        ];

        $gasLimit = $client->estimateGas($estimatePayload);
        $gasCostAtomic = $client->multiplyDecimalStrings($gasLimit, $gasPriceWei);

        if ($client->compareDecimalStrings($balanceAtomic, $gasCostAtomic) <= 0) {
            throw new RuntimeException(
                "Insufficient funds for gas on source address [{$sourceAddress}]."
            );
        }

        $maxSendableAtomic = $client->subtractDecimalStrings($balanceAtomic, $gasCostAtomic);

        $finalAtomic = $client->compareDecimalStrings($requestedAtomic, $maxSendableAtomic) <= 0
            ? $requestedAtomic
            : $maxSendableAtomic;

        if ($finalAtomic === '0') {
            throw new RuntimeException(
                "EVM payout amount after gas adjustment is zero for source address [{$sourceAddress}]."
            );
        }

        $finalAmountDecimal = $client->weiToDecimalString($finalAtomic, $decimals);

        $transaction = [
            'from' => $sourceAddress,
            'to' => $destinationAddress,
            'value' => $client->decimalToHexQuantity($finalAtomic),
            'nonce' => $client->toHexQuantity($nonce),
            'gas' => $client->decimalToHexQuantity($gasLimit),
            'gasPrice' => $client->decimalToHexQuantity($gasPriceWei),
        ];

        $signed = $this->signer->signTransaction($networkKey, $source, $transaction);
        $txHash = (string) ($signed['tx_hash'] ?? '');

        if ($txHash === '') {
            throw new RuntimeException('EVM signer returned empty tx hash.');
        }

        return new EvmPayoutResult(
            txHash: $txHash,
            fromAddress: $sourceAddress,
            toAddress: $destinationAddress,
            amountDecimal: $finalAmountDecimal,
            nonce: $nonce,
            gasPriceWei: $gasPriceWei,
            gasLimit: $gasLimit,
            maxFeePerGasWei: null,
            maxPriorityFeePerGasWei: null,
            meta: array_merge(
                [
                    'network_key' => $networkKey,
                    'asset_key' => $assetKey,
                    'requested_amount_decimal' => $amountDecimal,
                    'adjusted_amount_decimal' => $finalAmountDecimal,
                    'source_balance_atomic' => $balanceAtomic,
                    'estimated_gas_cost_atomic' => $gasCostAtomic,
                    'source_key_ref' => $source->keyRef,
                    'source_derivation_path' => $source->derivationPath,
                    'source_derivation_index' => $source->derivationIndex,
                ],
                is_array($signed['meta'] ?? null) ? $signed['meta'] : []
            ),
        );
    }
}
