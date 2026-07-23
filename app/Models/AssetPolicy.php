<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AssetPolicy extends Model
{
    public const MODE_DISABLED = 'disabled';

    public const MODE_INTERNAL_BALANCE_ONLY = 'internal_balance_only';

    public const MODE_IMMEDIATE = 'immediate';

    public const MODE_THRESHOLD = 'threshold';

    public const MODE_MANUAL = 'manual';

    public const MODES = [
        self::MODE_DISABLED,
        self::MODE_INTERNAL_BALANCE_ONLY,
        self::MODE_IMMEDIATE,
        self::MODE_THRESHOLD,
        self::MODE_MANUAL,
    ];

    protected $fillable = [
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
}
