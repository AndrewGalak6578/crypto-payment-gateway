<?php

declare(strict_types=1);

namespace App\Services\Custody;

use App\Exceptions\CustodyAccountingException;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

final class CustodyDecimal
{
    public const STORAGE_SCALE = 18;

    public function positive(mixed $value, int $assetScale): string
    {
        if (! is_string($value)) {
            throw new CustodyAccountingException('Custody amounts must be decimal strings.');
        }

        if ($assetScale < 0 || $assetScale > self::STORAGE_SCALE) {
            throw new CustodyAccountingException('Custody asset scale is outside the supported range.');
        }

        if (! preg_match('/^(0|[1-9][0-9]*)(?:\.([0-9]+))?$/D', $value, $matches)) {
            throw new CustodyAccountingException('Custody amount is not a canonical unsigned decimal string.');
        }

        $integerDigits = strlen(strtok($value, '.'));
        $fractionDigits = isset($matches[2]) ? strlen($matches[2]) : 0;

        if ($integerDigits > self::STORAGE_SCALE) {
            throw new CustodyAccountingException('Custody amount exceeds the supported integer precision.');
        }

        if ($fractionDigits > $assetScale) {
            throw new CustodyAccountingException('Custody amount exceeds the asset scale.');
        }

        $decimal = BigDecimal::of($value);
        if ($decimal->compareTo(BigDecimal::zero()) <= 0) {
            throw new CustodyAccountingException('Custody posting amounts must be greater than zero.');
        }

        return (string) $decimal->toScale($assetScale, RoundingMode::UNNECESSARY);
    }

    public function storage(BigDecimal|string $value): string
    {
        $decimal = $value instanceof BigDecimal ? $value : BigDecimal::of($value);

        return (string) $decimal->toScale(self::STORAGE_SCALE, RoundingMode::UNNECESSARY);
    }

    public function atomic(string $formattedAmount, int $assetScale, ?string $provided): ?string
    {
        if ($provided === null) {
            return null;
        }

        if (! preg_match('/^[1-9][0-9]*$/D', $provided)) {
            throw new CustodyAccountingException('Atomic amounts must be canonical positive integer strings.');
        }

        $fixed = (string) BigDecimal::of($formattedAmount)
            ->toScale($assetScale, RoundingMode::UNNECESSARY);
        $expected = ltrim(str_replace('.', '', $fixed), '0');
        $expected = $expected === '' ? '0' : $expected;

        if ($provided !== $expected) {
            throw new CustodyAccountingException('Atomic amount does not match the decimal posting amount.');
        }

        return $provided;
    }
}
