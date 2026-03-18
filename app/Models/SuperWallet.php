<?php

namespace App\Models;

use App\Support\Coin;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Class SuperWallet
 *
 * @property int $id
 * @property string $coin
 * @property string $wallet
 * @property float|null $fee_rate
 * @property int|null $merchant_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class SuperWallet extends Model
{
    protected $table = 'super_wallets';

    protected $fillable = [
        'coin', 'wallet', 'fee_rate', 'merchant_id'
    ];

    public static function forCoin(string $coin): ?self
    {
        $coin = Coin::normalize($coin);
        return static::where('coin', $coin)->first();
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }
}
