<?php

declare(strict_types=1);

namespace App\Services\Settlement;

use App\Contracts\SettlementAttemptEvidenceProviderInterface;
use App\Data\SettlementReconciliationResult;
use App\Models\MerchantSettlementAttempt;
use App\Services\Evm\EvmRpcClient;
use App\Support\Chains\ChainRegistry;
use App\Support\Coin;
use Brick\Math\BigDecimal;
use Throwable;

final readonly class RpcSettlementAttemptEvidenceProvider implements SettlementAttemptEvidenceProviderInterface
{
    private const ERC20_TRANSFER_TOPIC = '0xddf252ad1be2c89b69c2b068fc378daa952ba7f163c4a11628f55a4df523b3ef';

    public function __construct(
        private ChainRegistry $chains,
        private SettlementDecimal $decimal,
    ) {}

    public function inspect(MerchantSettlementAttempt $attempt): SettlementReconciliationResult
    {
        try {
            return match ($attempt->chain_family) {
                'evm' => $this->inspectEvm($attempt),
                'utxo' => $this->inspectUtxo($attempt),
                default => SettlementReconciliationResult::inconclusive('unsupported_chain_family'),
            };
        } catch (Throwable $e) {
            return SettlementReconciliationResult::inconclusive(
                'rpc_inspection_error',
                $attempt->txid,
                ['error' => $e->getMessage(), 'failure_class' => $e::class],
            );
        }
    }

    private function inspectEvm(MerchantSettlementAttempt $attempt): SettlementReconciliationResult
    {
        $chain = $this->chains->get($attempt->network_key);
        $rpcUrl = (string) ($chain['rpc_url'] ?? '');
        if ($rpcUrl === '') {
            return SettlementReconciliationResult::inconclusive('missing_evm_rpc_url', $attempt->txid);
        }

        $client = new EvmRpcClient($rpcUrl);
        $configuredChainId = (string) ($chain['chain_id'] ?? '');
        if (
            $configuredChainId === ''
            || $attempt->chain_id === null
            || (string) $attempt->chain_id !== $configuredChainId
            || (string) $client->chainId() !== $configuredChainId
        ) {
            return SettlementReconciliationResult::inconclusive('evm_chain_id_mismatch', $attempt->txid);
        }

        $txid = $attempt->txid;
        $transaction = $txid !== null ? $client->getTransactionByHash($txid) : null;

        if ($txid === null) {
            $transaction = $this->findEvmTransactionBySourceAndNonce($client, $attempt);
            $txid = is_array($transaction) ? strtolower((string) ($transaction['hash'] ?? '')) : null;
        }

        if ($txid === null || $txid === '' || $transaction === null) {
            return SettlementReconciliationResult::inconclusive(
                $attempt->txid === null ? 'evm_source_nonce_search_inconclusive' : 'evm_transaction_missing',
                $attempt->txid,
            );
        }

        if (strtolower((string) ($transaction['hash'] ?? '')) !== strtolower($txid)) {
            return SettlementReconciliationResult::inconclusive('evm_transaction_hash_mismatch', $txid);
        }

        $identityError = $this->validateEvmTransactionIdentity($client, $attempt, $transaction);
        if ($identityError !== null) {
            return SettlementReconciliationResult::inconclusive($identityError, $txid);
        }

        $receipt = $client->getTransactionReceipt($txid);
        if ($receipt === null || ($receipt['blockNumber'] ?? null) === null) {
            return SettlementReconciliationResult::pending('evm_transaction_pending', $txid);
        }

        if (
            isset($receipt['transactionHash'])
            && strtolower((string) $receipt['transactionHash']) !== strtolower($txid)
        ) {
            return SettlementReconciliationResult::inconclusive('evm_receipt_transaction_hash_mismatch', $txid);
        }

        $blockNumber = $client->hexToNullableInt((string) $receipt['blockNumber']);
        if ($blockNumber === null) {
            return SettlementReconciliationResult::inconclusive('evm_receipt_block_invalid', $txid);
        }

        $confirmations = max(0, $client->blockNumber() - $blockNumber + 1);
        $required = max(1, (int) $attempt->required_confirmations);
        if ($confirmations < $required) {
            return SettlementReconciliationResult::pending(
                'evm_confirmations_pending',
                $txid,
                $confirmations,
                ['required_confirmations' => $required, 'receipt_block_number' => $blockNumber],
            );
        }

        $status = strtolower((string) ($receipt['status'] ?? ''));
        $evidence = [
            'required_confirmations' => $required,
            'confirmations' => $confirmations,
            'receipt_block_number' => $blockNumber,
            'receipt_status' => $status,
        ];

        if ($status === '0x0' || $status === '0x00') {
            return SettlementReconciliationResult::failedSafe($txid, $confirmations, $evidence);
        }

        if ($status !== '0x1' && $status !== '0x01') {
            return SettlementReconciliationResult::inconclusive('evm_receipt_status_invalid', $txid, $evidence);
        }

        if (
            $attempt->transfer_type === MerchantSettlementAttempt::TRANSFER_ERC20
            && ! $this->hasMatchingErc20TransferLog($client, $attempt, $receipt)
        ) {
            return SettlementReconciliationResult::inconclusive('erc20_transfer_log_mismatch', $txid, $evidence);
        }

        return SettlementReconciliationResult::confirmed($txid, $confirmations, $evidence);
    }

    private function inspectUtxo(MerchantSettlementAttempt $attempt): SettlementReconciliationResult
    {
        if ($attempt->source_reference !== "rpc-wallet:{$attempt->network_key}") {
            return SettlementReconciliationResult::inconclusive('utxo_source_wallet_reference_mismatch', $attempt->txid);
        }

        $rpc = Coin::rpc($attempt->asset_key);
        $txid = $attempt->txid;

        if ($txid === null) {
            $matches = $rpc->findSentTransactionsByReference($attempt->broadcast_reference, 1000);
            $txids = array_values(array_unique(array_filter(array_map(
                static fn (array $transaction): string => (string) ($transaction['txid'] ?? ''),
                $matches,
            ))));

            if (count($txids) !== 1) {
                return SettlementReconciliationResult::inconclusive(
                    count($txids) > 1 ? 'utxo_multiple_reference_matches' : 'utxo_reference_not_found',
                );
            }

            $txid = $txids[0];
        }

        $transaction = $rpc->getWalletTransaction($txid);
        if ($transaction === null) {
            return SettlementReconciliationResult::inconclusive('utxo_wallet_transaction_missing', $txid);
        }

        if ((string) ($transaction['comment'] ?? '') !== $attempt->broadcast_reference) {
            return SettlementReconciliationResult::inconclusive('utxo_broadcast_reference_mismatch', $txid);
        }

        $matchingDetails = array_values(array_filter(
            is_array($transaction['details'] ?? null) ? $transaction['details'] : [],
            function (mixed $detail) use ($attempt): bool {
                if (! is_array($detail) || ($detail['category'] ?? '') !== 'send') {
                    return false;
                }

                $amount = BigDecimal::of((string) ($detail['amount'] ?? '0'))->abs();

                return (string) ($detail['address'] ?? '') === $attempt->destination_address
                    && $this->decimal->asset($amount, $attempt->asset_key)
                        ->compareTo($this->decimal->asset($attempt->amount_coin, $attempt->asset_key)) === 0;
            },
        ));

        if (count($matchingDetails) !== 1) {
            return SettlementReconciliationResult::inconclusive('utxo_destination_or_amount_mismatch', $txid);
        }

        $confirmations = max(0, (int) ($transaction['confirmations'] ?? 0));
        $required = max(1, (int) $attempt->required_confirmations);
        if ($confirmations < $required) {
            return SettlementReconciliationResult::pending(
                'utxo_confirmations_pending',
                $txid,
                $confirmations,
                ['required_confirmations' => $required],
            );
        }

        return SettlementReconciliationResult::confirmed(
            $txid,
            $confirmations,
            ['required_confirmations' => $required, 'wallet_owned' => true],
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findEvmTransactionBySourceAndNonce(
        EvmRpcClient $client,
        MerchantSettlementAttempt $attempt,
    ): ?array {
        if (
            $attempt->source_address === null
            || $attempt->nonce === null
            || $attempt->broadcast_block_number === null
        ) {
            return null;
        }

        $current = $client->blockNumber();
        $start = max(0, (int) $attempt->broadcast_block_number);
        $maxBlocks = max(1, (int) config('forwarding.attempts.evm_nonce_scan_blocks', 64));
        $end = min($current, $start + $maxBlocks - 1);
        $matches = [];

        for ($blockNumber = $start; $blockNumber <= $end; $blockNumber++) {
            $block = $client->getBlockByNumber($client->toHexQuantity($blockNumber), true);
            foreach (is_array($block['transactions'] ?? null) ? $block['transactions'] : [] as $transaction) {
                if (
                    is_array($transaction)
                    && strtolower((string) ($transaction['from'] ?? '')) === strtolower($attempt->source_address)
                    && $client->hexToDecimalStringValue((string) ($transaction['nonce'] ?? '0x0')) === (string) $attempt->nonce
                ) {
                    $matches[] = $transaction;
                }
            }
        }

        return count($matches) === 1 ? $matches[0] : null;
    }

    /**
     * @param  array<string, mixed>  $transaction
     */
    private function validateEvmTransactionIdentity(
        EvmRpcClient $client,
        MerchantSettlementAttempt $attempt,
        array $transaction,
    ): ?string {
        if (
            strtolower((string) ($transaction['from'] ?? '')) !== strtolower((string) $attempt->source_address)
            || $client->hexToDecimalStringValue((string) ($transaction['nonce'] ?? '0x0')) !== (string) $attempt->nonce
        ) {
            return 'evm_source_or_nonce_mismatch';
        }

        if (isset($transaction['chainId']) && (
            $client->hexToDecimalStringValue((string) $transaction['chainId']) !== (string) $attempt->chain_id
        )) {
            return 'evm_transaction_chain_id_mismatch';
        }

        if ($attempt->atomic_amount === null) {
            return 'evm_atomic_amount_missing';
        }

        if ($attempt->transfer_type === MerchantSettlementAttempt::TRANSFER_EVM_NATIVE) {
            return strtolower((string) ($transaction['to'] ?? '')) === strtolower($attempt->destination_address)
                && $client->hexToDecimalStringValue((string) ($transaction['value'] ?? '0x0')) === $attempt->atomic_amount
                    ? null
                    : 'evm_native_destination_or_value_mismatch';
        }

        if ($attempt->transfer_type === MerchantSettlementAttempt::TRANSFER_ERC20) {
            if (
                strtolower((string) ($transaction['to'] ?? '')) !== strtolower((string) $attempt->token_contract)
                || strtolower((string) ($transaction['input'] ?? $transaction['data'] ?? '')) !== strtolower((string) $attempt->calldata)
                || hash('sha256', strtolower((string) $attempt->calldata)) !== $attempt->calldata_fingerprint
            ) {
                return 'erc20_contract_or_calldata_mismatch';
            }

            return null;
        }

        return 'evm_transfer_type_unsupported';
    }

    /**
     * @param  array<string, mixed>  $receipt
     */
    private function hasMatchingErc20TransferLog(
        EvmRpcClient $client,
        MerchantSettlementAttempt $attempt,
        array $receipt,
    ): bool {
        $sourceTopic = '0x'.str_pad(
            substr(strtolower((string) $attempt->source_address), 2),
            64,
            '0',
            STR_PAD_LEFT,
        );
        $destinationTopic = '0x'.str_pad(
            substr(strtolower($attempt->destination_address), 2),
            64,
            '0',
            STR_PAD_LEFT,
        );

        foreach (is_array($receipt['logs'] ?? null) ? $receipt['logs'] : [] as $log) {
            if (! is_array($log)) {
                continue;
            }

            $topics = is_array($log['topics'] ?? null) ? $log['topics'] : [];
            if (
                strtolower((string) ($log['address'] ?? '')) === strtolower((string) $attempt->token_contract)
                && strtolower((string) ($topics[0] ?? '')) === self::ERC20_TRANSFER_TOPIC
                && strtolower((string) ($topics[1] ?? '')) === $sourceTopic
                && strtolower((string) ($topics[2] ?? '')) === $destinationTopic
                && $client->hexToDecimalStringValue((string) ($log['data'] ?? '0x0')) === $attempt->atomic_amount
            ) {
                return true;
            }
        }

        return false;
    }
}
