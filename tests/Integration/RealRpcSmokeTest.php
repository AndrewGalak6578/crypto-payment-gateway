<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Support\Coin;
use Tests\TestCase;

final class RealRpcSmokeTest extends TestCase
{
    public function test_real_rpc_endpoints_are_reachable_when_enabled(): void
    {
        $enabled = (bool) env('RUN_REAL_RPC_TESTS', false);

        if (!$enabled) {
            $this->markTestSkipped('Set RUN_REAL_RPC_TESTS=true to run real RPC smoke checks.');
        }

        config()->set('coins.mode', 'real');

        foreach (['btc', 'ltc', 'dash'] as $coin) {
            $rpc = Coin::rpc($coin);

            $address = $rpc->getNewAddress('smoke:' . $coin . ':' . uniqid('', true));
            self::assertNotSame('', $address);

            $totals = $rpc->getReceivedTotals($address, 1);
            self::assertArrayHasKey('confirmed', $totals);
            self::assertArrayHasKey('unconfirmed', $totals);
            self::assertArrayHasKey('all', $totals);

            $balance = $rpc->getBalance();
            self::assertIsFloat($balance);
        }
    }
}
