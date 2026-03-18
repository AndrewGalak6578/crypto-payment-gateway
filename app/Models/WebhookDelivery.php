<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Stored outbound webhook delivery with retry metadata.
 *
 * @property int $id
 * @property int $invoice_id
 * @property string $event
 * @property string $url
 * @property array $payload
 * @property string $signature
 * @property int $attempts
 * @property Carbon|null $next_retry_at
 * @property string $status
 * @property string|null $last_error
 * @property Carbon|null $delivered_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 *
 * @property-read Invoice $invoice
 */
class WebhookDelivery extends Model
{
    protected $fillable = [
        'invoice_id',
        'event',
        'url',
        'payload',
        'signature',
        'attempts',
        'next_retry_at',
        'status',
        'last_error',
        'delivered_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'next_retry_at' => 'datetime',
            'delivered_at' => 'datetime',
        ];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}
