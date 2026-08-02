<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class CustodyJournalSourceLink extends Model
{
    public const CREATED_AT = 'created_at';

    public const UPDATED_AT = null;

    public const SOURCE_KIND = 'live_explicit_internal_credit';

    public const SOURCE_VERSION = 1;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'asset_scale' => 'integer',
            'source_version' => 'integer',
            'source_snapshot_jsonb' => 'array',
            'created_at' => 'immutable_datetime',
        ];
    }

    public function settlementEntry(): BelongsTo
    {
        return $this->belongsTo(MerchantSettlementEntry::class, 'merchant_settlement_entry_id');
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(CustodyJournalEntry::class, 'custody_journal_entry_id');
    }
}
