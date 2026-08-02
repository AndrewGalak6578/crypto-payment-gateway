<?php

declare(strict_types=1);

namespace App\Services\Settlement;

use App\Exceptions\CustodyAccountingException;
use App\Support\Assets\AssetRegistry;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

final readonly class SettlementDecimal
{
    public function __construct(private AssetRegistry $assets) {}

    public function asset(mixed $value, string $assetKey): BigDecimal
    {
        return $this->scale($value, $this->assets->settlementScale($assetKey));
    }

    public function scale(mixed $value, int $scale): BigDecimal
    {
        return BigDecimal::of($this->stringValue($value))->toScale($scale, RoundingMode::HALF_UP);
    }

    public function format(mixed $value, string $assetKey): string
    {
        return (string) $this->asset($value, $assetKey);
    }

    public function assetExact(mixed $value, string $assetKey): BigDecimal
    {
        return $this->exact($value, $this->assets->settlementScale($assetKey));
    }

    public function formatExact(mixed $value, string $assetKey): string
    {
        return (string) $this->assetExact($value, $assetKey);
    }

    public function usdExact(mixed $value): string
    {
        return (string) $this->exact($value, 2);
    }

    public function atomicExact(mixed $value, string $assetKey): string
    {
        $scale = $this->assets->settlementScale($assetKey);
        $formatted = (string) $this->exact($value, $scale);
        $atomic = ltrim(str_replace('.', '', $formatted), '0');

        return $atomic === '' ? '0' : $atomic;
    }

    public function zero(string $assetKey): BigDecimal
    {
        return $this->asset('0', $assetKey);
    }

    public function positiveOrZero(BigDecimal $value, string $assetKey): BigDecimal
    {
        return $value->compareTo(BigDecimal::zero()) > 0
            ? $this->asset((string) $value, $assetKey)
            : $this->zero($assetKey);
    }

    public function percentage(mixed $amount, mixed $percent, string $assetKey): BigDecimal
    {
        return $this->asset($amount, $assetKey)
            ->multipliedBy(BigDecimal::of($this->stringValue($percent)))
            ->dividedBy('100', $this->assets->settlementScale($assetKey), RoundingMode::HALF_UP);
    }

    public function usd(mixed $coinAmount, mixed $rate): string
    {
        return (string) BigDecimal::of($this->stringValue($coinAmount))
            ->multipliedBy(BigDecimal::of($this->stringValue($rate)))
            ->toScale(2, RoundingMode::HALF_UP);
    }

    private function exact(mixed $value, int $scale): BigDecimal
    {
        if (! is_string($value)) {
            throw new CustodyAccountingException('Phase 2A decimal values must be strings.');
        }

        if (! preg_match('/^(0|[1-9][0-9]*)(?:\.([0-9]+))?$/D', $value)) {
            throw new CustodyAccountingException('Phase 2A decimal value is not canonical.');
        }

        try {
            return BigDecimal::of($value)->toScale($scale, RoundingMode::UNNECESSARY);
        } catch (\Throwable $e) {
            throw new CustodyAccountingException(
                "Phase 2A decimal value has non-zero precision beyond scale {$scale}.",
                previous: $e,
            );
        }
    }

    private function stringValue(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '0';
        }

        return (string) $value;
    }
}
