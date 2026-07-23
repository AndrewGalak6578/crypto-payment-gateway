<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MerchantAssetPolicy extends Model
{
    public const SCOPE_ALL = '*';

    protected $fillable = [
        'merchant_id',
        'scope_key',
        'asset_key',
        'network_key',
        'asset_enabled',
        'checkout_enabled',
        'forwarding_enabled',
        'settlement_mode',
        'min_sweep_amount',
        'max_gas_cost',
        'reason',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'asset_enabled' => 'boolean',
            'checkout_enabled' => 'boolean',
            'forwarding_enabled' => 'boolean',
            'min_sweep_amount' => 'decimal:18',
            'max_gas_cost' => 'decimal:18',
            'metadata' => 'array',
        ];
    }

    public static function scopeKey(?string $assetKey = null, ?string $networkKey = null): string
    {
        $assetKey = strtolower(trim((string) $assetKey));
        $networkKey = strtolower(trim((string) $networkKey));

        if ($assetKey === '') {
            return self::SCOPE_ALL;
        }

        return $assetKey.':'.$networkKey;
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }
}
