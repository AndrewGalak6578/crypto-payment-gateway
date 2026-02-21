<?php

declare(strict_types=1);

namespace App\Support;

use App\Services\CoinRpc;

class Coin
{
    public const SUPPORTED = ['dash', 'ltc', 'btc'];

    public static function normalize(?string $coin): string
    {
        $coin = strtolower($coin ?? 'dash');

        if (!in_array($coin, self::SUPPORTED, true)) {
            return 'dash';
        }

        return $coin;
    }

    public static function rpc(?string $coin = null): CoinRpc
    {
        $coin = self::normalize($coin);

        return match ($coin) {
            'btc' => app(CoinRpc::class), // TODO: Add BtcRpc
            'ltc' => app(CoinRpc::class), // TODO: Add LtcRpc
            default => app(CoinRpc::class) // TODO: Add DashRpc
        };
    }
}
