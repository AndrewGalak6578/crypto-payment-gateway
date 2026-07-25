<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\SuperWallet;
use App\Services\Settlement\SuperWalletResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\BuildsDomainData;
use Tests\TestCase;

final class SuperWalletResolverTest extends TestCase
{
    use BuildsDomainData;
    use RefreshDatabase;

    public function test_null_network_legacy_wallet_requires_the_registry_network(): void
    {
        $merchant = $this->createMerchant();
        $wallet = SuperWallet::query()->create([
            'merchant_id' => $merchant->id,
            'coin' => 'btc',
            'asset_key' => null,
            'network_key' => null,
            'wallet' => 'bcrt1qlegacynetworkguard',
        ]);
        $resolver = app(SuperWalletResolver::class);

        self::assertSame(
            $wallet->id,
            $resolver->resolveMerchantOwnedByAsset($merchant, 'btc', 'bitcoin')?->id,
        );
        self::assertNull($resolver->resolveMerchantOwnedByAsset($merchant, 'btc', 'litecoin'));
        self::assertNull($resolver->resolveByAsset($merchant, 'btc', 'litecoin'));
    }

    public function test_platform_legacy_fallback_uses_the_same_registry_network_guard(): void
    {
        config()->set('forwarding.allow_platform_wallet_fallback', true);
        $merchant = $this->createMerchant();
        $wallet = SuperWallet::query()->create([
            'merchant_id' => null,
            'coin' => 'btc',
            'asset_key' => null,
            'network_key' => null,
            'wallet' => 'bcrt1qlegacyplatformnetworkguard',
        ]);
        $resolver = app(SuperWalletResolver::class);

        self::assertSame(
            $wallet->id,
            $resolver->resolvePlatformFallbackByAsset('btc', 'bitcoin')?->id,
        );
        self::assertNull($resolver->resolvePlatformFallbackByAsset('btc', 'litecoin'));
        self::assertNull($resolver->resolveByAsset($merchant, 'btc', 'litecoin'));
    }
}
