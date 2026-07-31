<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class CustodyAccountBalance extends Model
{
    protected $primaryKey = 'account_id';

    public $incrementing = false;

    protected $guarded = ['account_id'];

    protected function casts(): array
    {
        return [
            'balance' => 'decimal:18',
            'revision' => 'integer',
            'rebuilt_at' => 'immutable_datetime',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(CustodyAccount::class, 'account_id');
    }

    public function lastJournalEntry(): BelongsTo
    {
        return $this->belongsTo(CustodyJournalEntry::class, 'last_journal_entry_id');
    }
}
