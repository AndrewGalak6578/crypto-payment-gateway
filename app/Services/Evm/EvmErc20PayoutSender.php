<?php

declare(strict_types=1);

namespace App\Services\Evm;

use App\Contracts\EvmTokenPayoutSenderInterface;
use App\Contracts\EvmTransactionSignerInterface;
use App\Data\EvmPayoutResult;
use App\Data\EvmSweepSource;
use App\Data\PreparedErc20Payout;
use App\Models\Invoice;
use App\Models\SuperWallet;
use App\Support\Assets\AssetRegistry;
use App\Support\Chains\ChainRegistry;
use RuntimeException;

final readonly class EvmErc20PayoutSender implements EvmTokenPayoutSenderInterface
{
    public function __construct(
        private ChainRegistry $chains,
        private AssetRegistry $assets,
        private EvmTransactionSignerInterface $signer,
        private EvmTokenGasChecker $gasChecker,
    ) {}

    public function prepareToken(
        Invoice $invoice,
        EvmSweepSource $source,
        SuperWallet $destination,
        string $amountDecimal,
    ): PreparedErc20Payout {
        $networkKey = $invoice->resolvedNetworkKey();
        $assetKey = $invoice->resolvedAssetKey();

        if ($networkKey !== $source->networkKey || $this->chains->family($networkKey) !== 'evm') {
            throw new RuntimeException("Invalid EVM source network [{$source->networkKey}] for [{$networkKey}].");
        }

        $asset = $this->assets->get($assetKey);
        if (
            strtolower((string) ($asset['type'] ?? 'native')) !== 'token'
            || strtolower((string) ($asset['token_standard'] ?? '')) !== 'erc20'
        ) {
            throw new RuntimeException("Asset [{$assetKey}] is not a supported ERC-20 token.");
        }

        $contractAddress = $this->validAddress((string) ($asset['contract_address'] ?? ''), 'token contract');
        $destinationAddress = $this->validAddress((string) $destination->wallet, 'destination wallet');
        $sourceAddress = $this->validAddress($source->address, 'source address');
        $chain = $this->chains->get($networkKey);
        $rpcUrl = (string) ($chain['rpc_url'] ?? '');

        if ($rpcUrl === '') {
            throw new RuntimeException("Missing rpc_url for EVM network [{$networkKey}].");
        }

        $client = new EvmRpcClient($rpcUrl);
        $chainId = $client->chainId();
        if ((string) $chainId !== (string) ($chain['chain_id'] ?? '')) {
            throw new RuntimeException(
                "RPC chain ID [{$chainId}] does not match configured network [{$networkKey}]."
            );
        }
        $amountAtomic = $client->decimalStringToAtomic($amountDecimal, (int) ($asset['decimals'] ?? 18));
        if ($amountAtomic === '0') {
            throw new RuntimeException('ERC-20 payout amount is zero.');
        }

        $calldata = $client->encodeErc20TransferData($destinationAddress, $amountAtomic);
        $callResult = $client->callContract([
            'from' => $sourceAddress,
            'to' => $contractAddress,
            'value' => '0x0',
            'data' => $calldata,
        ]);

        if (! $client->isTruthyErc20CallResult($callResult)) {
            throw new RuntimeException("ERC-20 preflight failed for asset [{$assetKey}].");
        }

        $gasCheck = $this->gasChecker->checkForTransaction(
            client: $client,
            fromAddress: $sourceAddress,
            toAddress: $contractAddress,
            data: $calldata,
        );

        if (! $gasCheck->hasEnoughGas) {
            throw new RuntimeException("Insufficient native gas for ERC-20 payout from [{$sourceAddress}].");
        }

        $nonce = $client->getTransactionCount($sourceAddress, 'pending');
        $transaction = [
            'from' => $sourceAddress,
            'to' => $contractAddress,
            'value' => '0x0',
            'data' => $calldata,
            'nonce' => $client->toHexQuantity($nonce),
            'gas' => $client->decimalToHexQuantity($gasCheck->gasLimit),
            'gasPrice' => $client->decimalToHexQuantity($gasCheck->gasPriceWei),
        ];

        return new PreparedErc20Payout(
            networkKey: $networkKey,
            assetKey: $assetKey,
            source: $source,
            contractAddress: $contractAddress,
            destinationAddress: $destinationAddress,
            amountDecimal: $amountDecimal,
            amountAtomic: $amountAtomic,
            calldata: $calldata,
            nonce: $nonce,
            chainId: $chainId,
            broadcastBlockNumber: $client->blockNumber(),
            transaction: $transaction,
            gasPriceWei: $gasCheck->gasPriceWei,
            gasLimit: $gasCheck->gasLimit,
            meta: [
                'estimated_gas_cost_wei' => $gasCheck->estimatedCostWei,
                'native_balance_wei' => $gasCheck->nativeBalanceWei,
                'source_key_ref' => $source->keyRef,
                'source_derivation_path' => $source->derivationPath,
                'source_derivation_index' => $source->derivationIndex,
            ],
        );
    }

    public function broadcastToken(PreparedErc20Payout $payout): EvmPayoutResult
    {
        $signed = $this->signer->signTransaction(
            $payout->networkKey,
            $payout->source,
            $payout->transaction,
        );
        $txHash = strtolower((string) ($signed['tx_hash'] ?? ''));

        if (! preg_match('/^0x[a-f0-9]{64}$/', $txHash)) {
            throw new RuntimeException('EVM signer returned an invalid ERC-20 tx hash.');
        }

        return new EvmPayoutResult(
            txHash: $txHash,
            fromAddress: strtolower($payout->source->address),
            toAddress: $payout->destinationAddress,
            amountDecimal: $payout->amountDecimal,
            nonce: $payout->nonce,
            gasPriceWei: $payout->gasPriceWei,
            gasLimit: $payout->gasLimit,
            meta: array_merge(
                [
                    'network_key' => $payout->networkKey,
                    'asset_key' => $payout->assetKey,
                    'token_standard' => 'erc20',
                    'token_contract' => $payout->contractAddress,
                    'amount_atomic' => $payout->amountAtomic,
                    'calldata_fingerprint' => $payout->calldataFingerprint(),
                    'transaction_fingerprint' => $payout->fingerprint(),
                ],
                $payout->meta,
                is_array($signed['meta'] ?? null) ? $signed['meta'] : [],
            ),
        );
    }

    private function validAddress(string $address, string $field): string
    {
        $address = strtolower(trim($address));

        if (! preg_match('/^0x[a-f0-9]{40}$/', $address)) {
            throw new RuntimeException("Invalid ERC-20 {$field} [{$address}].");
        }

        return $address;
    }
}
