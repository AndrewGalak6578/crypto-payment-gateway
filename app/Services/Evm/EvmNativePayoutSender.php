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

    public function sendNative(Invoice $invoice, EvmSweepSource $source, SuperWallet $destination, string $amountDecimal): EvmPayoutResult
    {
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

        $destinationAddress = strtolower((string)$destination->wallet);
        if ($destinationAddress === '') {
            throw new RuntimeException(
                "Destination super wallet [{$destination->id}] has empty wallet "
            );
        }

        $chain = $this->chains->get($networkKey);
        $rpcUrl = (string)($chain['rpc_url'] ?? '');

        if ($rpcUrl === '') {
            throw new RuntimeException("Missing rpc_url for EVM network [{$networkKey}].");
        }

        $client = new EvmRpcClient($rpcUrl);
        $decimals = (int) ($this->assets->get($assetKey)['decimals'] ?? 18);

        $valueAtomic = $client->decimalStringToAtomic($amountDecimal, $decimals);
        if ($valueAtomic === '0') {
            throw new RuntimeException("EVM payout amount is zero.");
        }

        $nonce = $client->getTransactionCount($source->address, 'pending');
        $gasPriceWei = $client->gasPriceWei();

        $txForEstimate = [
            'from' => strtolower($source->address),
            'to' => $destinationAddress,
            'value' => $client->decimalToHexQuantity($valueAtomic),
        ];

        $gasLimit = $client->estimateGas($txForEstimate);

        $transaction = [
            'from' => strtolower($source->address),
            'to' => $destinationAddress,
            'value' => $client->decimalToHexQuantity($valueAtomic),
            'nonce' => $client->toHexQuantity($nonce),
            'gas' => $client->decimalToHexQuantity($gasLimit),
            'gasPrice' => $client->decimalToHexQuantity($gasPriceWei),
        ];

        $signed = $this->signer->signTransaction($networkKey, $source, $transaction);
        $txHash = (string) ($signed['tx_hash'] ?? null);

        if ($txHash === '') {
            throw new RuntimeException("EVM signer returned empty tx_hash.");
        }

        return new EvmPayoutResult(
            txHash: $txHash,
            fromAddress: strtolower($source->address),
            toAddress: $destinationAddress,
            amountDecimal: $amountDecimal,
            nonce: $nonce,
            gasPriceWei: $gasPriceWei,
            gasLimit: $gasLimit,
            maxFeePerGasWei: null,
            maxPriorityFeePerGasWei: null,
            meta: array_merge(
                [
                    'networkKey' => $networkKey,
                    'assetKey' => $assetKey,
                    'source_key_ref' => $source->keyRef,
                    'source_derivation_path' => $source->derivationPath,
                    'source_derivation_index' => $source->derivationIndex
                ],
                is_array($signed['meta'] ?? null) ? $signed['meta'] : []
            )
        );
    }
}
