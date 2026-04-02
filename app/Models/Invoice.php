<?php

namespace App\Models;

use App\Support\Assets\AssetRegistry;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use RuntimeException;

/**
 * Invoice state snapshot used by API, hosted page and settlement pipeline.
 *
 * @property int $id
 * @property int $merchant_id
 * @property string|null $public_id
 * @property string|null $external_id
 * @property string|null $status
 * @property string|null $coin
 * @property string $asset_key
 * @property string $network_key
 * @property string|null $pay_address
 * @property float|null $amount_coin
 * @property float|null $expected_usd
 * @property float|null $rate_usd
 * @property Carbon|null $expires_at
 * @property Carbon|null $fixated_at
 * @property Carbon|null $paid_at
 * @property Carbon|null $monitor_until
 * @property string|null $first_txid
 * @property float|null $first_amount_coin
 * @property float|null $received_conf_coin
 * @property float|null $received_all_coin
 * @property float|null $paid_usd
 * @property float|null $fee_coin
 * @property float|null $merchant_payout_coin
 * @property float|null $fee_usd
 * @property float|null $merchant_payout_usd
 * @property string|null $forward_status
 * @property string|null $forward_attempt_uuid
 * @property array|null $metadata
 * @property array|null $forward_txids
 * @property Carbon|null $last_forwarded_at
 * @property Carbon|null $forwarding_started_at
 * @property float|null $forwarded_coin
 * @property float|null $forwarding_coin
 * @property-read Merchant $merchant
 * @property-read HasMany<WebhookDelivery> $webhookDeliveries
 */
class Invoice extends Model
{
    protected $table = 'invoices';

    protected $fillable = [
        'merchant_id', 'public_id', 'external_id',
        'status', 'coin', 'asset_key', 'network_key',
        'pay_address',
        'amount_coin', 'expected_usd', 'rate_usd',
        'expires_at', 'fixated_at', 'paid_at', 'monitor_until',
        'first_txid', 'first_amount_coin',
        'received_conf_coin', 'received_all_coin',
        'paid_usd', 'fee_usd', 'merchant_payout_usd',
        'forward_status', 'metadata', 'forwarded_coin',
        'forward_txids', 'last_forwarded_at',
        'forward_attempt_uuid', 'forwarding_coin',
        'forwarding_started_at', 'fee_coin', 'merchant_payout_coin'
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'fixated_at' => 'datetime',
            'paid_at' => 'datetime',
            'monitor_until' => 'datetime',
            'last_forwarded_at' => 'datetime',
            'forwarding_started_at' => 'datetime',
            'metadata' => 'array',
            'forward_txids' => 'array',
            'forwarded_coin' => 'decimal:8',
            'forwarding_coin' => 'decimal:8',
            'fee_coin' => 'decimal:8',
            'merchant_payout_coin' => 'decimal:8',
        ];
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    public function webhookDeliveries(): HasMany
    {
        return $this->hasMany(WebhookDelivery::class);
    }

    public function resolvedAssetKey(): string
    {
        if (is_string($this->asset_key) && $this->asset_key !== '') {
            return strtolower($this->asset_key);
        }

        if (is_string($this->coin) && $this->coin !== '') {
            return strtolower($this->coin);
        }

        throw new RuntimeException('Invoice has no asset key');
    }

    /**
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    public function resolvedNetworkKey(): string
    {
        if (is_string($this->network_key) && $this->network_key !== '') {
            return strtolower($this->network_key);
        }

        $asset = app(AssetRegistry::class)->get($this->resolvedAssetKey());

        return (string) $asset['network'];
    }

    public function syncResolvedAssetFields(): void
    {
        $assetKey = $this->resolvedAssetKey();
        $asset = app(AssetRegistry::class)->get($assetKey);

        $this->asset_key = $assetKey;
        $this->network_key = (string) $asset['network'];

        if (!$this->coin) {
            $this->coin = $assetKey;
        }
    }
}
