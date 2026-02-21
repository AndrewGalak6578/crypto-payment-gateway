<?php
declare(strict_types=1);

namespace App\Services;

interface CoinRpc
{
    public function getNewAddress(string $label = ''): string;
    public function getReceivedByAddress(string $address, int $minConf = 1): float;
    public function getTransactionsByAddress(string $address, int $minConf = 1, int $count = 1000, ?string $label = null): array;
    public function getReceivedTotals(string $address): array;
    public function listTransactions(int $count = 1000, int $skip = 0): array;
    public function getTransaction(string $txid): array;
    public function sendToAddress(string $address, float $amount, ?float $feeRate = null): string;
    public function getBalance(): float;
}
