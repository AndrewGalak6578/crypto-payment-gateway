<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\AssetPolicyResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class AssetPolicyResolverTest extends TestCase
{
    use RefreshDatabase;

    public function test_asset_policy_rejects_a_mismatched_enabled_network(): void
    {
        $resolver = app(AssetPolicyResolver::class);

        self::assertTrue($resolver->isAssetEnabled('btc', 'bitcoin'));
        self::assertFalse($resolver->isAssetEnabled('btc', 'litecoin'));
    }
}
