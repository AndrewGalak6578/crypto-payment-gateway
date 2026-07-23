<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\Evm\EvmRpcClient;
use PHPUnit\Framework\TestCase;

final class EvmRpcClientArithmeticTest extends TestCase
{
    public function test_unsigned_integer_arithmetic_preserves_zero_operands_and_large_wei_values(): void
    {
        $client = new EvmRpcClient('http://evm-rpc.invalid');

        self::assertSame('1000000000000000000', $client->addDecimalStrings('1000000000000000000', '0'));
        self::assertSame('1000000000000000000', $client->addDecimalStrings('0', '1000000000000000000'));
        self::assertSame(
            '1000000000000000000000000000000000000',
            $client->addDecimalStrings('999999999999999999999999999999999999', '1'),
        );
        self::assertSame('999999999999999999', $client->subtractDecimalStrings('1000000000000000000', '1'));
        self::assertSame('21000000000000', $client->multiplyDecimalStrings('21000', '1000000000'));
    }
}
