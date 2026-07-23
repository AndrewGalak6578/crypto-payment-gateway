<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Services\CoinBasedLogic\CoinRpc;

/**
 * In-memory RPC test double with controllable totals, transactions and send calls.
 */
final class FakeCoinRpc implements CoinRpc
{
    /** @var array<int, array<string, mixed>> */
    public array $txs = [];

    /** @var array{confirmed: float, unconfirmed: float, all: float} */
    public array $totals = [
        'confirmed' => 0.0,
        'unconfirmed' => 0.0,
        'all' => 0.0,
    ];

    /** @var array<int, array{address: string, amount: float, fee_rate: float|null, reference: string|null}> */
    public array $sendCalls = [];

    public string $nextTxid = 'fake_txid_1';

    public bool $throwAfterBroadcast = false;

    /** @var array<string, array<string, mixed>> */
    public array $walletTransactions = [];

    /** @var array<int, array<string, mixed>> */
    public array $sentTransactions = [];

    public function getNewAddress(string $label = ''): string
    {
        return 'mock_addr_'.md5($label.microtime(true));
    }

    /**
     * @return array{confirmed: float, unconfirmed: float, all: float}
     */
    public function getReceivedTotals(string $address, int $confirmedMinConf): array
    {
        return $this->totals;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getTransactionsByAddress(
        string $address,
        int $minConf = 1,
        int $count = 1000,
        ?string $label = null
    ): array {
        return $this->txs;
    }

    public function sendToAddress(
        string $address,
        float $amount,
        ?float $feeRate = null,
        ?string $reference = null,
    ): string {
        $this->sendCalls[] = [
            'address' => $address,
            'amount' => $amount,
            'fee_rate' => $feeRate,
            'reference' => $reference,
        ];

        $transaction = [
            'txid' => $this->nextTxid,
            'confirmations' => 0,
            'comment' => $reference,
            'details' => [[
                'category' => 'send',
                'address' => $address,
                'amount' => -$amount,
            ]],
        ];
        $this->walletTransactions[$this->nextTxid] = $transaction;
        $this->sentTransactions[] = [
            'txid' => $this->nextTxid,
            'category' => 'send',
            'address' => $address,
            'amount' => -$amount,
            'confirmations' => 0,
            'comment' => $reference,
        ];

        if ($this->throwAfterBroadcast) {
            throw new \RuntimeException('Simulated timeout after broadcast.');
        }

        return $this->nextTxid;
    }

    public function getBalance(): float
    {
        return 1000.0;
    }

    public function getWalletTransaction(string $txid): ?array
    {
        return $this->walletTransactions[$txid] ?? null;
    }

    public function findSentTransactionsByReference(string $reference, int $count = 1000): array
    {
        return array_values(array_filter(
            $this->sentTransactions,
            static fn (array $transaction): bool => ($transaction['comment'] ?? null) === $reference,
        ));
    }
}
