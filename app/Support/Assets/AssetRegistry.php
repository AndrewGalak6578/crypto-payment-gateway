<?php
declare(strict_types=1);

namespace App\Support\Assets;

use RuntimeException;

final class AssetRegistry
{
    /**
     * Returns all registered assets.
     * @return array<string, array<string, mixed>>
     */
    public function all(): array
    {
        return config('assets', []);
    }

    /**
     * Returns all enabled assets.
     * @return array<string, array<string, mixed>>
     */
    public function enabled(): array
    {
        return array_filter(
            $this->all(),
            static fn(array $asset): bool => (bool)($asset['enabled'] ?? false)
        );
    }

    /**
     * Returns all asset keys.
     * @param bool $onlyEnabled Whether to only include enabled assets
     * @return list<string>
     */
    public function keys(bool $onlyEnabled = true): array
    {
        $source = $onlyEnabled ? $this->enabled() : $this->all();
        return array_values(array_keys($source));
    }

    /**
     * Returns whether an asset exists.
     *
     * @param string $assetKey Asset key.
     * @param bool $onlyEnabled Whether to only include enabled assets
     * @return bool
     */
    public function exists(string $assetKey, bool $onlyEnabled = true): bool
    {
        $assetKey = strtolower($assetKey);
        $source = $onlyEnabled ? $this->enabled() : $this->all();

        return array_key_exists($assetKey, $source);
    }

    /**
     * Returns an asset by key.
     * @param string $assetKey Asset key.
     * @return array<string, mixed>
     */
    public function get(string $assetKey): array
    {
        $assetKey = strtolower($assetKey);
        $all = $this->all();

        if (!array_key_exists($assetKey, $all)) {
            throw new RuntimeException("Unsupported asset: {$assetKey}");
        }

        return $all[$assetKey];
    }

    /**
     * Returns the network of an asset.
     * @param string $assetKey Asset key.
     */
    public function network(string $assetKey): string
    {
        return (string) $this->get($assetKey)['network'];
    }

    /**
     * Returns the symbol of an asset.
     * @param string $assetKey Asset key.
     * @return string coin symbol
     */
    public function symbol(string $assetKey): string
    {
        return (string) $this->get($assetKey)['symbol'];
    }

    public function settlementScale(string $assetKey): int
    {
        return (int) $this->get($assetKey)['settlement_scale'];
    }

    public function epsilon(string $assetKey): float
    {
        return (float) $this->get($assetKey)['epsilon'];
    }
}
