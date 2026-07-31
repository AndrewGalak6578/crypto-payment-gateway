<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class CustodyJournalPosting extends Model
{
    public const CREATED_AT = 'created_at';

    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'line_number' => 'integer',
            'created_at' => 'immutable_datetime',
        ];
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(CustodyJournalEntry::class, 'journal_entry_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(CustodyAccount::class, 'account_id');
    }
}
