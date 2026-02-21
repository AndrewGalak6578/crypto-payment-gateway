<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
