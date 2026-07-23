<?php

declare(strict_types=1);

namespace App\Services\CoinBasedLogic;

/**
 * Unified RPC contract used by invoice lifecycle and settlement services.
 */
interface CoinRpc
{
    /**
     * Returns new wallet address for receiving funds.
     */
    public function getNewAddress(string $label = ''): string;

    /**
     * Returns totals for an address split by confirmation level.
     *
     * @return array{confirmed: float, unconfirmed: float, all: float}
     */
    public function getReceivedTotals(string $address, int $confirmedMinConf): array;

    /**
     * Returns incoming transactions for a payment address.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getTransactionsByAddress(string $address, int $minConf = 1, int $count = 1000, ?string $label = null): array;

    /**
     * Sends amount to destination address and returns transfer txid.
     */
    public function sendToAddress(
        string $address,
        float $amount,
        ?float $feeRate = null,
        ?string $reference = null,
    ): string;

    /**
     * Returns a wallet-owned transaction or null when the wallet has no record.
     *
     * @return array<string, mixed>|null
     */
    public function getWalletTransaction(string $txid): ?array;

    /**
     * Returns wallet send history entries carrying the unique settlement reference.
     *
     * @return array<int, array<string, mixed>>
     */
    public function findSentTransactionsByReference(string $reference, int $count = 1000): array;

    /**
     * Returns wallet available balance.
     */
    public function getBalance(): float;
}
