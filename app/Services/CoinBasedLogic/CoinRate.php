<?php

declare(strict_types=1);

namespace App\Services\CoinBasedLogic;

use App\Support\Assets\AssetRegistry;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Resolves USD rates with provider fallback and safety guards.
 */
class CoinRate
{
    public function __construct(
        private readonly AssetRegistry $assets
    )
    {
    }

    /**
     * Returns market rate for given coin symbol.
     *
     * @param string $coin Normalized coin symbol.
     * @throws \RuntimeException When no sane rate can be resolved.
     */
    public function usd(string $coin = 'dash'): float
    {
        $coin = strtolower($coin);
        $asset = $this->assets->get($coin);
        $rateMeta = $asset['rate'] ?? [];

        $cacheKey = "coinrate.usd.$coin";
        $lastGoodKey = "coinrate.usd.$coin.last_good";

        $cached = Cache::get($cacheKey);
        if (is_numeric($cached) && $this->isSaneRate($coin, (float)$cached)) {
            return (float)$cached;
        }

        $providers = [
            'coingecko' => fn() => $this->fromCoinGecko($coin),
            'coinbase' => fn() => $this->fromCoinbase($coin)
        ];

        foreach ($providers as $name => $fetch) {
            try {
                $rate = $fetch();
                if ($rate !== null && $this->isSaneRate($rateMeta, $rate)) {
                    Cache::put($cacheKey, $rate, now()->addMinutes(5));
                    Cache::put($lastGoodKey, $rate, now()->addDay(2));
                    return $rate;
                }

                Log::warning("CoinRate: provider returned bad rate", [
                    'provider' => $name,
                    'coin' => $coin,
                    'rate' => $rate
                ]);
            } catch (\Throwable $e) {
                Log::warning("CoinRate: provider failed", [
                    'provider' => $name,
                    'coin' => $coin,
                    'error' => $e->getMessage()
                ]);
            }
        }

        $last = Cache::get($lastGoodKey);
        if (is_numeric($last) && $this->isSaneRate($rateMeta, (float)$last)) {
            Cache::put($cacheKey, (float)$last, now()->addMinutes(1));
            return (float)$last;
        }

        throw new \RuntimeException("Rate for {$coin} unavailable");
    }

    /**
     * Reads spot price from CoinGecko.
     *
     * @param string $coin Normalized coin symbol.
     */
    private function fromCoinGecko(array $rateMeta): ?float
    {
        $id = $rateMeta['coingecko_id'] ?? null;
        if (!$id) return null;

        $resp = Http::timeout(5)->retry(2, 200)->get(
            'https://api.coingecko.com/api/v3/simple/price',
            ['ids' => $id, 'vs_currencies' => 'usd']
        );

        if (!$resp->ok()) return null;

        $data = $resp->json();

        return isset($data[$id]['usd']) ? (float)$data[$id]['usd'] : null;
    }

    /**
     * Reads spot price from Coinbase.
     *
     * @param string $coin Normalized coin symbol.
     */
    private function fromCoinbase(array $rateMeta): ?float
    {
        $pair = $rateMeta['coinbase_pair'] ?? null;

        if (!$pair) return null;

        $resp = Http::timeout(5)->retry(2, 200)->get("https://api.coinbase.com/v2/prices/{$pair}/spot");
        if (!$resp->ok()) return null;

        $data = $resp->json();
        $amount = $data['data']['amount'] ?? null;

        return is_numeric($amount) ? (float)$amount : null;
    }

    /**
     * Basic sanity checks against obvious provider outliers.
     *
     * @param string $coin Normalized coin symbol.
     * @param float $rate Candidate rate.
     */
    private function isSaneRate(array $rateMeta, float $rate): bool
    {
        if ($rate <= 0) return false;

        $min = (float)($rateMeta['sanity_min'] ?? 0.00001);
        $max = (float)($rateMeta['sanity_max'] ?? 1000000);

        return $rate > $min && $rate < $max;
    }
}
