<?php

declare(strict_types=1);

namespace App\Support;

use App\Services\BtcRpc;
use App\Services\CoinRpc;
use App\Services\DashRpc;
use App\Services\LtcRpc;
use App\Services\MockRpc;

/**
 * Coin utility helpers for normalization and RPC provider resolution.
 */
class Coin
{
    public const SUPPORTED = ['dash', 'ltc', 'btc'];

    /**
     * Normalizes unsupported values to default coin.
     *
     * @param string|null $coin Raw coin symbol.
     * @return string
     */
    public static function normalize(?string $coin): string
    {
        $coin = strtolower($coin ?? 'dash');

        if (!in_array($coin, self::SUPPORTED, true)) {
            return 'dash';
        }

        return $coin;
    }

    /**
     * Returns RPC implementation based on runtime mode and coin symbol.
     *
     * @param string|null $coin Raw coin symbol.
     */
    public static function rpc(?string $coin = null): CoinRpc
    {
        $coin = self::normalize($coin);

        if (config('coins.mode', 'mock') === 'mock') {
            return app(MockRpc::class);
        }

        return match ($coin) {
            'btc' => app(BtcRpc::class),
            'ltc' => app(LtcRpc::class),
            default => app(DashRpc::class)
        };
    }
}
