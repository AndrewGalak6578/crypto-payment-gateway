<?php
declare(strict_types=1);

namespace App\Support\Chains;

use App\Services\CoinBasedLogic\BtcRpc;
use App\Services\CoinBasedLogic\CoinRpc;
use App\Services\CoinBasedLogic\DashRpc;
use App\Services\CoinBasedLogic\LtcRpc;
use App\Support\Assets\AssetRegistry;

use RuntimeException;
final class ChainManager
{
    public function __construct(
        private readonly ChainRegistry $chains,
        private readonly AssetRegistry $assets
    )
    {
    }

    /**
     * Returns the CoinRpc driver for the given asset key.
     *
     * @param string $assetKey Asset key.
     * @return CoinRpc
     */
    public function driverForAsset(string $assetKey): CoinRpc
    {
        $networkKey = $this->assets->network($assetKey);

        return $this->driverForNetwork($networkKey);
    }

    /**
     * Returns the CoinRpc driver for the given network key.
     *
     * @param string $networkKey Network key.
     * @return CoinRpc
     */
    public function driverForNetwork(string $networkKey): CoinRpc
    {
        $driver = $this->chains->driver($networkKey);

        return match ($driver) {
            'btc' => app(BtcRpc::class),
            'ltc' => app(LtcRpc::class),
            'dash' => app(DashRpc::class),
            default => throw new RuntimeException("Unsupported chain driver: {$driver}")
        };
    }
}
