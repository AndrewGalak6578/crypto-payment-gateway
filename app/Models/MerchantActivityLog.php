<?php
declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MerchantActivityLog extends Model
{
    protected $fillable = [
        'merchant_id',
        'actor_merchant_user_id',
        'subject_merchant_user_id',
        'section',
        'type',
        'action',
        'target_type',
        'target_id',
        'target_label',
        'ip_address',
        'user_agent',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
    ];

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(MerchantUser::class, 'actor_merchant_user_id');
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(MerchantUser::class, 'subject_merchant_user_id');
    }
}
