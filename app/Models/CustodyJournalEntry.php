<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

final class CustodyJournalEntry extends Model
{
    public const CREATED_AT = 'created_at';

    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'asset_scale' => 'integer',
            'immutable_metadata' => 'array',
            'effective_at' => 'immutable_datetime',
            'posted_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    public function postings(): HasMany
    {
        return $this->hasMany(CustodyJournalPosting::class, 'journal_entry_id')
            ->orderBy('line_number');
    }

    public function reversalOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reversal_of_id');
    }

    public function reversal(): HasOne
    {
        return $this->hasOne(self::class, 'reversal_of_id')
            ->whereNotNull('posted_at');
    }
}
