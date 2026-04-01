<?php

declare(strict_types=1);

namespace App\Support;

use App\Services\CoinBasedLogic\BtcRpc;
use App\Services\CoinBasedLogic\CoinRpc;
use App\Services\CoinBasedLogic\DashRpc;
use App\Services\CoinBasedLogic\LtcRpc;
use App\Services\CoinBasedLogic\MockRpc;
use App\Support\Assets\AssetRegistry;
use App\Support\Chains\ChainManager;

/**
 * Coin utility helpers for normalization and RPC provider resolution.
 */
class Coin
{
    public const SUPPORTED = ['dash', 'ltc', 'btc'];


    public static function supported(): array
    {
        return app(AssetRegistry::class)->keys();
    }

    /**
     * Normalizes unsupported values to default coin.
     *
     * @param string|null $coin Raw coin symbol.
     * @return string
     */
    public static function normalize(?string $coin): string
    {
        $coin = strtolower((string) $coin);
        $registry = app(AssetRegistry::class);

        if ($coin !== '' && $registry->exists($coin)) {
            return $coin;
        }

        // Сохраняем текущее поведение, чтобы не ломать старый flow
        return 'dash';
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

        return app(ChainManager::class)->driverForAsset($coin);
    }
}
