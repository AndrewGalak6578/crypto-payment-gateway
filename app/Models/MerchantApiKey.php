<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Class MerchantApiKey
 *
 * @property int $id
 * @property int $merchant_id
 * @property string|null $name
 * @property string $token_hash
 * @property Carbon|null $last_used_at
 * @property Carbon|null $revoked_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 *
 * @property Merchant $merchant
 */
class MerchantApiKey extends Model
{
    protected $table = 'merchant_api_keys';

    protected $fillable = [
        'merchant_id', 'name', 'token_hash', 'last_used_at', 'revoked_at'
    ];

    protected $casts = [
        'last_used_at' => 'datetime',
        'revoked_at' => 'datetime'
    ];

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }
}
