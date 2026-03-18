<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;


/**
 * Class MerchantBalance
 * @property int $id
 * @property int $merchant_id
 * @property string $coin
 * @property float $amount
 */
class MerchantBalance extends Model
{
    protected $fillable = [
        'merchant_id', 'coin', 'amount'
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:8'
        ];
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }
}
