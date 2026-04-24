<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;


/**
 * Internal merchant balance for coins that were not forwarded on-chain.
 *
 * @property int $id
 * @property int $merchant_id
 * @property string $coin
 * @property float $amount
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 *
 * @property-read Merchant $merchant
 */
class MerchantBalance extends Model
{
    protected $fillable = [
        'merchant_id', 'coin', 'amount'
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:18'
        ];
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }
}
