<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Immutable-ish settlement activity entry for merchant money movement history.
 */
class MerchantSettlementEntry extends Model
{
    public const TYPE_FORWARD_SENT = 'forward_sent';

    public const TYPE_FORWARD_HELD = 'forward_held';

    public const TYPE_INTERNAL_CREDIT = 'internal_credit';

    public const STATUS_PENDING = 'pending';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    public const STATUS_DEFERRED = 'deferred';

    protected $fillable = [
        'merchant_id',
        'invoice_id',
        'settlement_attempt_id',
        'asset_key',
        'network_key',
        'type',
        'status',
        'amount_coin',
        'fee_coin',
        'amount_usd',
        'destination_wallet',
        'txid',
        'idempotency_key',
        'error_message',
        'metadata',
        'occurred_at',
    ];

    protected $casts = [
        'amount_coin' => 'decimal:18',
        'fee_coin' => 'decimal:18',
        'amount_usd' => 'decimal:2',
        'metadata' => 'array',
        'occurred_at' => 'datetime',
    ];

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function settlementAttempt(): BelongsTo
    {
        return $this->belongsTo(MerchantSettlementAttempt::class, 'settlement_attempt_id');
    }

    public function custodyJournalSourceLink(): HasOne
    {
        return $this->hasOne(CustodyJournalSourceLink::class, 'merchant_settlement_entry_id');
    }
}
