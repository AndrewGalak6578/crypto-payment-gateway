<?php
declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $merchant_id
 * @property int|null $invoice_id
 * @property string $network_key
 * @property string $asset_key
 * @property string $address
 * @property string $family
 * @property string|null $address_type
 * @property string $strategy
 * @property string $status
 * @property string|null $derivation_path
 * @property int|null $derivation_index
 * @property string|null $key_ref
 * @property Carbon|null $issued_at
 * @property Carbon|null $assigned_at
 * @property Carbon|null $released_at
 * @property array<string, mixed>|null $meta
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 *
 * @property-read Merchant $merchant
 * @property-read Invoice|null $invoice
 */
class PaymentAddress extends Model
{
    protected $table = 'payment_addresses';

    protected $fillable = [
        'merchant_id',
        'invoice_id',
        'network_key',
        'asset_key',
        'address',
        'family',
        'address_type',
        'strategy',
        'status',
        'derivation_path',
        'derivation_index',
        'key_ref',
        'issued_at',
        'assigned_at',
        'released_at',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'meta' => 'array',
            'issued_at' => 'datetime',
            'assigned_at' => 'datetime',
            'released_at' => 'datetime',
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

    public function markAssignedToInvoice(Invoice $invoice): void
    {
        $this->forceFill([
            'invoice_id' => $invoice->id,
            'status' => 'assigned',
            'assigned_at' => now('UTC'),
        ])->save();
    }

    public function markRetired(?array $meta = null): void
    {
        $payload = [
            'status' => 'retired',
            'released_at' => now('UTC'),
        ];

        if ($meta !== null) {
            $payload['meta'] = array_merge($this->meta ?? [], $meta);
        }

        $this->forceFill($payload)->save();
    }
}

