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
 * @property string $tx_hash
 * @property string $status
 * @property array<string, mixed>|null $meta
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 *
 * @property-read Invoice|null $invoice
 */
class EvmGasFunding extends Model
{
    protected $table = 'evm_gas_fundings';

    protected $fillable = [
        'invoice_id',
        'network_key',
        'asset_key',
        'source_address',
        'target_address',
        'amount_native_wei',
        'tx_hash',
        'status',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'meta' => 'array',
        ];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}
