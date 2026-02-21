<?php
declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Str;

class MockRpc implements CoinRpc
{

    public function getNewAddress(string $label = ''): string
    {
        return 'mock_' . Str::lower(Str::random(30));
    }

    public function getReceivedTotals(string $address): array
    {
        return ['confirmed' => 0.0, 'unconfirmed' => 0.0, 'all' => 0.0];
    }

    public function getTransactionsByAddress(string $address, int $minConf = 1, int $count = 1000, ?string $label = null): array
    {
        return [];
    }

    public function sendToAddress(string $address, float $amount, ?float $feeRate = null): string
    {
        return 'mock_' . Str::lower(Str::random(24));
    }

    public function getBalance(): float
    {
        return 0.0;
    }
}
