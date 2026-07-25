<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class MerchantSettlementPreference extends Model
{
    /** Largest integer represented exactly by both PHP and JavaScript JSON clients. */
    public const MAX_EXPECTED_REVISION = 9_007_199_254_740_991;

    protected $fillable = [
        'merchant_id',
        'asset_key',
        'network_key',
        'requested_mode',
        'requested_minimum_invoice_payout',
        'revision',
    ];

    protected function casts(): array
    {
        return [
            'requested_minimum_invoice_payout' => 'decimal:18',
            'revision' => 'integer',
        ];
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }
}
