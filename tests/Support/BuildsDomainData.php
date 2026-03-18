<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\Invoice;
use App\Models\Merchant;
use App\Models\MerchantApiKey;

trait BuildsDomainData
{
    protected function createMerchant(array $overrides = []): Merchant
    {
        return Merchant::query()->create(array_merge([
            'name' => 'Test Merchant',
            'status' => 'active',
            'fee_percent' => 1.5,
            'webhook_url' => 'https://merchant.test/webhook',
            'webhook_secret' => 'secret_123',
        ], $overrides));
    }

    /** @return array{0: MerchantApiKey, 1: string} */
    protected function createApiKey(Merchant $merchant, array $overrides = []): array
    {
        $plain = $overrides['plain'] ?? 'merchant_token_123';
        unset($overrides['plain']);

        $key = MerchantApiKey::query()->create(array_merge([
            'merchant_id' => $merchant->id,
            'name' => 'default',
            'token_hash' => hash('sha256', $plain),
            'revoked_at' => null,
        ], $overrides));

        return [$key, $plain];
    }

    protected function createInvoice(Merchant $merchant, array $overrides = []): Invoice
    {
        return Invoice::query()->create(array_merge([
            'merchant_id' => $merchant->id,
            'public_id' => strtolower(bin2hex(random_bytes(8))),
            'external_id' => null,
            'status' => 'pending',
            'coin' => 'btc',
            'pay_address' => 'bcrt1qtestaddress123',
            'amount_coin' => 0.01000000,
            'expected_usd' => 100.00,
            'rate_usd' => 10000.00,
            'expires_at' => now('UTC')->addHour(),
            'monitor_until' => now('UTC')->addHours(25),
            'received_conf_coin' => 0,
            'received_all_coin' => 0,
            'forward_status' => 'none',
            'metadata' => [],
        ], $overrides));
    }
}
