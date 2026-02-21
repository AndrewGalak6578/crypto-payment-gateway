<?php
declare(strict_types=1);

namespace App\Services;

interface CoinRpc
{
    public function getNewAddress(string $label = ''): string;
    public function getReceivedTotals(string $address): array;
    public function getTransactionsByAddress(string $address, int $minConf = 1, int $count = 1000, ?string $label = null): array;
    public function sendToAddress(string $address, float $amount, ?float $feeRate = null): string;
    public function getBalance(): float;
}
