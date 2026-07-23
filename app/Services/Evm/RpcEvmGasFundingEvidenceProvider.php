<?php

declare(strict_types=1);

namespace App\Services\Evm;

use App\Contracts\EvmGasFundingEvidenceProviderInterface;
use App\Data\EvmGasFundingReconciliationResult;
use App\Models\EvmGasFunding;
use App\Support\Chains\ChainRegistry;
use Throwable;

final readonly class RpcEvmGasFundingEvidenceProvider implements EvmGasFundingEvidenceProviderInterface
{
    public function __construct(
        private ChainRegistry $chains,
        private EvmRpcClientFactory $clients,
    ) {}

    public function inspect(EvmGasFunding $funding): EvmGasFundingReconciliationResult
    {
        try {
            return $this->inspectSafely($funding);
        } catch (Throwable $e) {
            return EvmGasFundingReconciliationResult::inconclusive(
                'rpc_inspection_error',
                $funding->tx_hash,
                ['error' => $e->getMessage(), 'failure_class' => $e::class],
            );
        }
    }

    private function inspectSafely(EvmGasFunding $funding): EvmGasFundingReconciliationResult
    {
        $chain = $this->chains->get($funding->network_key);
        $rpcUrl = trim((string) ($chain['rpc_url'] ?? ''));
        $configuredChainId = (string) ($chain['chain_id'] ?? '');
        if ($rpcUrl === '' || $configuredChainId === '') {
            return EvmGasFundingReconciliationResult::inconclusive('missing_evm_network_configuration', $funding->tx_hash);
        }

        $client = $this->clients->make($rpcUrl);
        if (
            $funding->chain_id === null
            || (string) $funding->chain_id !== $configuredChainId
            || (string) $client->chainId() !== $configuredChainId
        ) {
            return EvmGasFundingReconciliationResult::inconclusive('evm_chain_id_mismatch', $funding->tx_hash);
        }

        if ($funding->nonce === null || $funding->broadcast_block_number === null) {
            return EvmGasFundingReconciliationResult::inconclusive('funding_nonce_or_block_snapshot_missing', $funding->tx_hash);
        }

        $txHash = $funding->tx_hash !== null ? strtolower($funding->tx_hash) : null;
        $transaction = $txHash !== null ? $client->getTransactionByHash($txHash) : null;

        if ($transaction === null) {
            $transaction = $this->findBySourceAndNonce($client, $funding);
            $recoveredHash = is_array($transaction) ? strtolower((string) ($transaction['hash'] ?? '')) : '';
            if ($recoveredHash !== '') {
                $txHash = $recoveredHash;
            }
        }

        if ($txHash === null || $txHash === '' || $transaction === null) {
            return EvmGasFundingReconciliationResult::inconclusive(
                $funding->tx_hash === null
                    ? 'evm_source_nonce_search_inconclusive'
                    : 'evm_gas_funding_transaction_missing',
                $funding->tx_hash,
            );
        }

        $identityError = $this->validateIdentity($client, $funding, $transaction, $txHash);
        if ($identityError !== null) {
            return EvmGasFundingReconciliationResult::inconclusive($identityError, $txHash);
        }

        $receipt = $client->getTransactionReceipt($txHash);
        if ($receipt === null || ($receipt['blockNumber'] ?? null) === null) {
            return EvmGasFundingReconciliationResult::pending('evm_gas_funding_pending', $txHash);
        }

        if (
            isset($receipt['transactionHash'])
            && strtolower((string) $receipt['transactionHash']) !== $txHash
        ) {
            return EvmGasFundingReconciliationResult::inconclusive('evm_receipt_transaction_hash_mismatch', $txHash);
        }

        $receiptBlock = $client->hexToNullableInt((string) $receipt['blockNumber']);
        if ($receiptBlock === null) {
            return EvmGasFundingReconciliationResult::inconclusive('evm_receipt_block_invalid', $txHash);
        }

        $confirmations = max(0, $client->blockNumber() - $receiptBlock + 1);
        $required = max(1, (int) $funding->required_confirmations);
        $evidence = [
            'required_confirmations' => $required,
            'confirmations' => $confirmations,
            'receipt_block_number' => $receiptBlock,
            'receipt_status' => strtolower((string) ($receipt['status'] ?? '')),
            'identity_verified' => true,
        ];

        if ($confirmations < $required) {
            return EvmGasFundingReconciliationResult::pending(
                'evm_gas_funding_confirmations_pending',
                $txHash,
                $confirmations,
                $evidence,
            );
        }

        $status = $evidence['receipt_status'];
        if ($status === '0x0' || $status === '0x00') {
            return EvmGasFundingReconciliationResult::failedSafe($txHash, $confirmations, $evidence);
        }

        if ($status !== '0x1' && $status !== '0x01') {
            return EvmGasFundingReconciliationResult::inconclusive('evm_receipt_status_invalid', $txHash, $evidence);
        }

        return EvmGasFundingReconciliationResult::confirmed($txHash, $confirmations, $evidence);
    }

    /** @return array<string, mixed>|null */
    private function findBySourceAndNonce(EvmRpcClient $client, EvmGasFunding $funding): ?array
    {
        $current = $client->blockNumber();
        $start = max(0, (int) $funding->broadcast_block_number);
        $maxBlocks = max(1, (int) config('payment_addresses.evm.gas_topup.nonce_scan_blocks', 64));
        $end = min($current, $start + $maxBlocks - 1);
        $matches = [];

        for ($blockNumber = $start; $blockNumber <= $end; $blockNumber++) {
            $block = $client->getBlockByNumber($client->toHexQuantity($blockNumber), true);
            foreach (is_array($block['transactions'] ?? null) ? $block['transactions'] : [] as $transaction) {
                if (
                    is_array($transaction)
                    && strtolower((string) ($transaction['from'] ?? '')) === strtolower($funding->source_address)
                    && $client->hexToDecimalStringValue((string) ($transaction['nonce'] ?? '0x0')) === (string) $funding->nonce
                ) {
                    $matches[] = $transaction;
                }
            }
        }

        return count($matches) === 1 ? $matches[0] : null;
    }

    /** @param array<string, mixed> $transaction */
    private function validateIdentity(
        EvmRpcClient $client,
        EvmGasFunding $funding,
        array $transaction,
        string $txHash,
    ): ?string {
        if (strtolower((string) ($transaction['hash'] ?? '')) !== $txHash) {
            return 'evm_transaction_hash_mismatch';
        }

        if (
            strtolower((string) ($transaction['from'] ?? '')) !== strtolower($funding->source_address)
            || $client->hexToDecimalStringValue((string) ($transaction['nonce'] ?? '0x0')) !== (string) $funding->nonce
        ) {
            return 'evm_source_or_nonce_mismatch';
        }

        if (
            isset($transaction['chainId'])
            && $client->hexToDecimalStringValue((string) $transaction['chainId']) !== (string) $funding->chain_id
        ) {
            return 'evm_transaction_chain_id_mismatch';
        }

        if (
            strtolower((string) ($transaction['to'] ?? '')) !== strtolower($funding->target_address)
            || $client->hexToDecimalStringValue((string) ($transaction['value'] ?? '0x0')) !== $funding->amount_native_wei
        ) {
            return 'evm_gas_funding_target_or_value_mismatch';
        }

        return null;
    }
}
