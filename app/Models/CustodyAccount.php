<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

final class CustodyAccount extends Model
{
    public const UPDATED_AT = null;

    public const SIDE_DEBIT = 'debit';

    public const SIDE_CREDIT = 'credit';

    public const CODE_MERCHANT_AVAILABLE = 'merchant_available';

    public const CODE_MERCHANT_RESERVED = 'merchant_reserved';

    public const CODE_MERCHANT_HELD = 'merchant_held';

    public const CODE_DEPOSIT_UNCOLLECTED = 'deposit_uncollected';

    public const CODE_TREASURY_AVAILABLE = 'treasury_available';

    public const CODE_TREASURY_RESERVED = 'treasury_reserved';

    public const CODE_OUTBOUND = 'outbound';

    public const CODE_FEE_REVENUE = 'fee_revenue';

    public const CODE_NETWORK_FEE_EXPENSE = 'network_fee_expense';

    public const CODE_INTERNAL_CREDIT_SHADOW_OFFSET = 'internal_credit_shadow_offset';

    public const CODE_MIGRATION_SUSPENSE = 'migration_suspense';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'asset_scale' => 'integer',
            'created_at' => 'immutable_datetime',
        ];
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    public function postings(): HasMany
    {
        return $this->hasMany(CustodyJournalPosting::class, 'account_id');
    }

    public function balanceProjection(): HasOne
    {
        return $this->hasOne(CustodyAccountBalance::class, 'account_id');
    }
}
