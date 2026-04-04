<?php
declare(strict_types=1);

namespace App\Support\Chains;

use RuntimeException;

final class ChainRegistry
{
    /**
     * Returns all registered chains.
     * @return array<string, array<string, mixed>>
     */
    public function all(): array
    {
        return config('chains', []);
    }

    /**
     * Returns the chain configuration for the given network.
     * @param string $networkKey Network key.
     * @return array<string, mixed>
     */
    public function get(string $networkKey): array
    {
        $networkKey = strtolower($networkKey);
        $all = $this->all();

        if (!array_key_exists($networkKey, $all)) {
            throw new RuntimeException("Unsupported network: {$networkKey}");
        }

        return $all[$networkKey];
    }

    /**
     * Returns the family of a network.
     * @param string $networkKey Network key.
     * @return string
     */
    public function family(string $networkKey): string
    {
        return (string) $this->get($networkKey)['family'];
    }

    /**
     * Returns the driver of a network.
     * @param string $networkKey Network key.
     * @return string
     */
    public function driver(string $networkKey): string
    {
        return (string) $this->get($networkKey)['driver'];
    }

    /**
     * Returns the number of confirmations required for a network.
     * @param string $networkKey Network key.
     * @return int
     */
    public function confirmations(string $networkKey): int
    {
        return (int) $this->get($networkKey)['confirmations'];
    }
}
