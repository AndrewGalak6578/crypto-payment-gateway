<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Custody;

use App\Exceptions\CustodyAccountingException;
use App\Services\Custody\CustodyDecimal;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CustodyDecimalTest extends TestCase
{
    #[DataProvider('invalidAmounts')]
    public function test_rejects_non_canonical_or_unsafe_amounts(mixed $amount, int $scale): void
    {
        $this->expectException(CustodyAccountingException::class);

        (new CustodyDecimal)->positive($amount, $scale);
    }

    /**
     * @return iterable<string, array{mixed, int}>
     */
    public static function invalidAmounts(): iterable
    {
        yield 'float' => [0.1, 18];
        yield 'integer' => [1, 18];
        yield 'exponent' => ['1e-8', 18];
        yield 'positive sign' => ['+1', 18];
        yield 'negative' => ['-1', 18];
        yield 'leading zero' => ['01.0', 18];
        yield 'leading decimal point' => ['.1', 18];
        yield 'trailing decimal point' => ['1.', 18];
        yield 'whitespace' => [' 1', 18];
        yield 'zero' => ['0', 18];
        yield 'negative zero' => ['-0', 18];
        yield 'excessive scale' => ['0.0000001', 6];
        yield 'excessive integer digits' => ['1000000000000000000', 18];
    }

    public function test_preserves_exact_18_8_and_6_decimal_values(): void
    {
        $decimal = new CustodyDecimal;

        self::assertSame('0.000000000000000001', $decimal->positive('0.000000000000000001', 18));
        self::assertSame('0.00000001', $decimal->positive('0.00000001', 8));
        self::assertSame('0.000001', $decimal->positive('0.000001', 6));
        self::assertSame('1.230000', $decimal->positive('1.23', 6));
    }

    public function test_atomic_amount_must_exactly_match_scaled_decimal(): void
    {
        $decimal = new CustodyDecimal;

        self::assertSame('1230000', $decimal->atomic('1.230000', 6, '1230000'));

        $this->expectException(CustodyAccountingException::class);
        $decimal->atomic('1.230000', 6, '123');
    }
}
