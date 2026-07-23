<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int|null $invoice_id
 * @property string $network_key
 * @property string|null $asset_key
 * @property string $source_address
 * @property string $target_address
 * @property string $amount_native_wei
 * @property string|null $tx_hash
 * @property string $status
 * @property string $state
 * @property bool $retry_safe
 * @property array<string, mixed>|null $meta
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Invoice|null $invoice
 */
class EvmGasFunding extends Model
{
    public const STATE_RESERVED = 'reserved';

    public const STATE_BROADCASTING = 'broadcasting';

    public const STATE_BROADCASTED = 'broadcasted';

    public const STATE_CONFIRMED = 'confirmed';

    public const STATE_FAILED = 'failed';

    public const STATE_NEEDS_RECONCILIATION = 'needs_reconciliation';

    protected $table = 'evm_gas_fundings';

    protected $fillable = [
        'invoice_id',
        'funding_uuid',
        'network_key',
        'asset_key',
        'source_address',
        'target_address',
        'amount_native_wei',
        'tx_hash',
        'status',
        'state',
        'retry_safe',
        'chain_id',
        'nonce',
        'required_confirmations',
        'broadcast_block_number',
        'transaction_fingerprint',
        'error_message',
        'reserved_at',
        'broadcasting_at',
        'broadcasted_at',
        'confirmed_at',
        'failed_at',
        'reconciliation_required_at',
        'last_reconciled_at',
        'reconciliation_attempts',
        'next_reconciliation_at',
        'reconciliation_owner_token',
        'reconciliation_lease_expires_at',
        'continuation_dispatched_at',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'meta' => 'array',
            'retry_safe' => 'boolean',
            'required_confirmations' => 'integer',
            'broadcast_block_number' => 'integer',
            'reserved_at' => 'datetime',
            'broadcasting_at' => 'datetime',
            'broadcasted_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'failed_at' => 'datetime',
            'reconciliation_required_at' => 'datetime',
            'last_reconciled_at' => 'datetime',
            'reconciliation_attempts' => 'integer',
            'next_reconciliation_at' => 'datetime',
            'reconciliation_lease_expires_at' => 'datetime',
            'continuation_dispatched_at' => 'datetime',
        ];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}
