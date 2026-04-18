<?php

namespace App\Services\Evm;

use App\Contracts\EvmTokenPayoutSenderInterface;
use App\Contracts\EvmTransactionSignerInterface;
use App\Data\EvmPayoutResult;
use App\Data\EvmSweepSource;
use App\Models\Invoice;
use App\Models\SuperWallet;
use App\Support\Assets\AssetRegistry;
use App\Support\Chains\ChainRegistry;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use RuntimeException;

final class EvmErc20PayoutSender implements EvmTokenPayoutSenderInterface
{

    public function __construct(
        private readonly ChainRegistry $chains,
        private readonly AssetRegistry $assets,
        private readonly EvmTransactionSignerInterface $signer,
        private readonly EvmTokenGasChecker $gasChecker,
    )
    {
    }

    /**
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    public function sendToken(Invoice $invoice, EvmSweepSource $source, SuperWallet $destination, string $amountDecimal): EvmPayoutResult
    {
        $networkKey = $invoice->resolvedNetworkKey();
        $assetKey = $invoice->resolvedAssetKey();

        if ($networkKey !== $source->networkKey) {
            throw new RuntimeException(
                "Source Network [{$source->networkKey}] does not match Invoice Network Key [{$networkKey}]."
            );
        }

        if ($this->chains->family($networkKey) !== 'evm') {
            throw new RuntimeException("Network [{$networkKey}] is not EVM");
        }

        $asset = $this->assets->get($assetKey);
        $assetType = strtolower((string)($asset['type'] ?? 'native'));
        $tokenStandard = strtolower((string)($asset['token_standard'] ?? ''));

        if ($assetType !== 'token' || $tokenStandard !== 'erc20') {
            throw new RuntimeException(
                "Asset [{$assetKey}] is not supported ERC-20 token asset."
            );
        }

        $contractAddress = strtolower(trim((string)($asset['contract_address'] ?? '')));
        if (!preg_match('/^0x[a-f0-9]{40}$/', $contractAddress)) {
            throw new RuntimeException(
                "Asset [{$assetKey}] has invalid ERC-20 contract address [{$contractAddress}]."
            );
        }

        $destinationAddress = strtolower(trim($destination->wallet));
        if (!preg_match('/^0x[a-f0-9]{40}$/', $destinationAddress)) {
            throw new RuntimeException(
                "Destination super wallet [{$destination->id}] has invalid wallet address."
            );
        }

        $chain = $this->chains->get($networkKey);
        $rpcUrl = (string) ($chain['rpc_url'] ?? '');

        if ($rpcUrl === '') {
            throw new RuntimeException("Missing rpc_url for EVM network [{$networkKey}].");
        }

        $client = new EvmRpcClient($rpcUrl);
        $decimals = (int) ($asset['decimals'] ?? 18);

        $sourceAddress = strtolower(trim($source->address));

        if (!preg_match('/^0x[a-f0-9]{40}$/', $sourceAddress)) {
            throw new RuntimeException(
                "Source address [{$sourceAddress}] is invalid for ERC-20 payout."
            );
        }

        $amountAtomic = $client->decimalStringToAtomic($amountDecimal, $decimals);
        if ($amountAtomic === '0') {
            throw new RuntimeException("ERC-20 payout amount is zero");
        }

        $data = $client->encodeErc20TransferData($destinationAddress, $amountAtomic);

        $callResult = $client->callContract([
            'from' => $sourceAddress,
            'to' => $contractAddress,
            'value' => '0x0',
            'data' => $data,
        ]);

        if (!$client->isTruthyErc20CallResult($callResult)) {
            throw new RuntimeException(
                "ERC-20 preflight eth_call failed for asset [{$assetKey}] from [{$sourceAddress}] to [{$destinationAddress}]."
            );
        }

        $gasCheck = $this->gasChecker->checkForTransaction(
            client: $client,
            fromAddress: $sourceAddress,
            toAddress: $contractAddress,
            data: $data,
        );

        if (!$gasCheck->hasEnoughGas) {
            throw new RuntimeException(
                "Insufficient native gas for ERC-20 payout from [{$sourceAddress}]"
            );
        }

        $nonce = $client->getTransactionCount($sourceAddress, 'pending');

        $transaction = [
            'from' => $sourceAddress,
            'to' => $contractAddress,
            'value' => '0x0',
            'data' => $data,
            'nonce' => $client->toHexQuantity($nonce),
            'gas' => $client->decimalToHexQuantity($gasCheck->gasLimit),
            'gasPrice' => $client->decimalToHexQuantity($gasCheck->gasPriceWei),
        ];

        $signed = $this->signer->signTransaction($networkKey, $source, $transaction);
        $txHash = (string) ($signed['tx_hash'] ?? '');

        if ($txHash === '') {
            throw new RuntimeException("EVM signer returned empty tx hash for ERC-20 payout.");
        }

        return new EvmPayoutResult(
            txHash: $txHash,
            fromAddress: $sourceAddress,
            toAddress: $destinationAddress,
            amountDecimal: $amountDecimal,
            nonce: $nonce,
            gasPriceWei: $gasCheck->gasPriceWei,
            gasLimit: $gasCheck->gasLimit,
            maxFeePerGasWei: null,
            maxPriorityFeePerGasWei: null,
            meta: array_merge(
                [
                    'network_key' => $networkKey,
                    'asset_key' => $assetKey,
                    'token_standard' => 'erc20',
                    'token_contract' => $contractAddress,
                    'source_key_ref' => $source->keyRef,
                    'source_derivation_path' => $source->derivationPath,
                    'source_derivation_index' => $source->derivationIndex,
                    'estimated_gas_cost_wei' => $gasCheck->estimatedCostWei,
                    'native_balance_wei' => $gasCheck->nativeBalanceWei
                ],
                is_array($signed['meta'] ?? null) ? $signed['meta'] : []
            )
        );
    }
}
