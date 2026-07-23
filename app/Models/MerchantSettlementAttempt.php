<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class MerchantSettlementAttempt extends Model
{
    public const STATE_RESERVED = 'reserved';

    public const STATE_BROADCASTING = 'broadcasting';

    public const STATE_BROADCASTED = 'broadcasted';

    public const STATE_CONFIRMED = 'confirmed';

    public const STATE_COMPLETED = 'completed';

    public const STATE_FAILED = 'failed';

    public const STATE_NEEDS_RECONCILIATION = 'needs_reconciliation';

    public const STATES = [
        self::STATE_RESERVED,
        self::STATE_BROADCASTING,
        self::STATE_BROADCASTED,
        self::STATE_CONFIRMED,
        self::STATE_COMPLETED,
        self::STATE_FAILED,
        self::STATE_NEEDS_RECONCILIATION,
    ];

    public const TRANSFER_UTXO = 'utxo';

    public const TRANSFER_EVM_NATIVE = 'evm_native';

    public const TRANSFER_ERC20 = 'erc20';

    protected $fillable = [
        'attempt_uuid',
        'merchant_id',
        'invoice_id',
        'asset_key',
        'network_key',
        'chain_family',
        'transfer_type',
        'state',
        'retry_safe',
        'amount_coin',
        'prepared_amount_coin',
        'broadcast_amount_coin',
        'fee_coin_snapshot',
        'merchant_payout_coin_snapshot',
        'source_address',
        'source_reference',
        'destination_address',
        'token_contract',
        'nonce',
        'chain_id',
        'required_confirmations',
        'broadcast_block_number',
        'atomic_amount',
        'calldata',
        'calldata_fingerprint',
        'txid',
        'broadcast_reference',
        'transaction_fingerprint',
        'error_message',
        'metadata',
        'reserved_at',
        'broadcasting_at',
        'broadcasted_at',
        'confirmed_at',
        'completed_at',
        'failed_at',
        'reconciliation_required_at',
        'last_reconciled_at',
        'reconciliation_attempts',
        'next_reconciliation_at',
        'reconciliation_owner_token',
        'reconciliation_lease_expires_at',
        'lease_owner_token',
        'lease_expires_at',
        'heartbeat_at',
    ];

    protected function casts(): array
    {
        return [
            'retry_safe' => 'boolean',
            'required_confirmations' => 'integer',
            'broadcast_block_number' => 'integer',
            'reconciliation_attempts' => 'integer',
            'amount_coin' => 'decimal:18',
            'prepared_amount_coin' => 'decimal:18',
            'broadcast_amount_coin' => 'decimal:18',
            'fee_coin_snapshot' => 'decimal:18',
            'merchant_payout_coin_snapshot' => 'decimal:18',
            'metadata' => 'array',
            'reserved_at' => 'datetime',
            'broadcasting_at' => 'datetime',
            'broadcasted_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'completed_at' => 'datetime',
            'failed_at' => 'datetime',
            'reconciliation_required_at' => 'datetime',
            'last_reconciled_at' => 'datetime',
            'next_reconciliation_at' => 'datetime',
            'reconciliation_lease_expires_at' => 'datetime',
            'lease_expires_at' => 'datetime',
            'heartbeat_at' => 'datetime',
        ];
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function settlementEntries(): HasMany
    {
        return $this->hasMany(MerchantSettlementEntry::class, 'settlement_attempt_id');
    }

    public function mayHaveBroadcast(): bool
    {
        return in_array($this->state, [
            self::STATE_BROADCASTING,
            self::STATE_BROADCASTED,
            self::STATE_CONFIRMED,
            self::STATE_NEEDS_RECONCILIATION,
        ], true);
    }
}
