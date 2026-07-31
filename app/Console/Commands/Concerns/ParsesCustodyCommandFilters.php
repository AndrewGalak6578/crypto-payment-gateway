<?php

declare(strict_types=1);

namespace App\Console\Commands\Concerns;

use App\Support\Assets\AssetRegistry;
use InvalidArgumentException;

trait ParsesCustodyCommandFilters
{
    private function positiveMerchantOption(): ?int
    {
        $value = $this->option('merchant');

        if ($value === null || $value === '') {
            return null;
        }

        if (! is_string($value) || preg_match('/\\A[1-9][0-9]*\\z/D', $value) !== 1) {
            throw new InvalidArgumentException(
                'The --merchant option must be a positive base-10 integer.',
            );
        }

        $maximum = (string) PHP_INT_MAX;
        if (
            strlen($value) > strlen($maximum)
            || (strlen($value) === strlen($maximum) && strcmp($value, $maximum) > 0)
        ) {
            throw new InvalidArgumentException(
                'The --merchant option exceeds the supported integer range.',
            );
        }

        return (int) $value;
    }

    private function registeredAssetOption(AssetRegistry $assets): ?string
    {
        $assetKey = $this->assetOption();

        if ($assetKey !== null && ! $assets->exists($assetKey, false)) {
            throw new InvalidArgumentException("Unknown asset [{$assetKey}] for --asset.");
        }

        return $assetKey;
    }

    private function assetOption(): ?string
    {
        $value = $this->option('asset');

        if ($value === null || $value === '') {
            return null;
        }

        if (! is_string($value) || trim($value) !== $value) {
            throw new InvalidArgumentException(
                'The --asset option must be a non-empty asset key without surrounding whitespace.',
            );
        }

        return strtolower($value);
    }
}
