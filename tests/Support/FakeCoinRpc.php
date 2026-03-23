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

    /** @var array<int, array{address: string, amount: float, fee_rate: float|null}> */
    public array $sendCalls = [];

    public string $nextTxid = 'fake_txid_1';

    /**
     * @param string $label
     */
    public function getNewAddress(string $label = ''): string
    {
        return 'mock_addr_' . md5($label . microtime(true));
    }

    /**
     * @param string $address
     * @param int $confirmedMinConf
     * @return array{confirmed: float, unconfirmed: float, all: float}
     */
    public function getReceivedTotals(string $address, int $confirmedMinConf): array
    {
        return $this->totals;
    }

    /**
     * @param string $address
     * @param int $minConf
     * @param int $count
     * @param string|null $label
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

    /**
     * @param string $address
     * @param float $amount
     * @param float|null $feeRate
     * @return string
     */
    public function sendToAddress(string $address, float $amount, ?float $feeRate = null): string
    {
        $this->sendCalls[] = [
            'address' => $address,
            'amount' => $amount,
            'fee_rate' => $feeRate,
        ];

        return $this->nextTxid;
    }

    /**
     * @return float
     */
    public function getBalance(): float
    {
        return 1000.0;
    }
}
