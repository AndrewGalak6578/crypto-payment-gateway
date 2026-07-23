<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\BuildsDomainData;
use Tests\TestCase;

final class InvoiceTest extends TestCase
{
    use BuildsDomainData;
    use RefreshDatabase;

    public function test_coin_amount_formatting_uses_asset_scale_without_losing_evm_precision(): void
    {
        $merchant = $this->createMerchant();

        $btcInvoice = $this->createInvoice($merchant, [
            'asset_key' => 'btc',
            'network_key' => 'bitcoin',
            'amount_coin' => '0.002000000000000000',
        ]);
        $ethInvoice = $this->createInvoice($merchant, [
            'coin' => 'eth_local',
            'asset_key' => 'eth_local',
            'network_key' => 'evm_local',
            'amount_coin' => '0.123456789012345678',
        ]);
        $usdtInvoice = $this->createInvoice($merchant, [
            'coin' => 'eth_usdt_local',
            'asset_key' => 'eth_usdt_local',
            'network_key' => 'evm_local',
            'amount_coin' => '12.345678000000000000',
        ]);

        self::assertSame('0.00200000', $btcInvoice->fresh()->formattedCoinAmount('amount_coin'));
        self::assertSame('0.123456789012345678', $ethInvoice->fresh()->formattedCoinAmount('amount_coin'));
        self::assertSame('12.345678', $usdtInvoice->fresh()->formattedCoinAmount('amount_coin'));
        self::assertSame('0.123456789012345678', (string) $ethInvoice->fresh()->getRawOriginal('amount_coin'));
    }
}
