<?php

namespace App\Models;

use App\Support\Assets\AssetRegistry;
use App\Support\Coin;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * Destination wallet used for invoice forwarding.
 *
 * @property int $id
 * @property string $coin
 * @property string $network_key
 * @property string $asset_key
 * @property string $wallet
 * @property float|null $fee_rate
 * @property int|null $merchant_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 *
 * @property-read Merchant|null $merchant
 */
class SuperWallet extends Model
{
    protected $table = 'super_wallets';

    protected $fillable = [
        'coin', 'network_key', 'asset_key', 'wallet', 'fee_rate', 'merchant_id'
    ];

    /**
     * Legacy helper: returns first wallet by asset key stored in coin column.
     */
    public static function forCoin(string $coin): ?self
    {
        $coin = Coin::normalize($coin);
        return static::query()
            ->where(function (Builder $query) use ($coin) {
                $query->where('asset_key', $coin)
                    ->orWhere('coin', $coin);
            })
            ->first();
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    public function resolvedAssetKey(): string
    {
        if (is_string($this->asset_key) && $this->asset_key !== '') {
            return strtolower($this->asset_key);
        }
        if (is_string($this->coin) && $this->coin !== '') {
            return strtolower($this->coin);
        }

        throw new RuntimeException("SuperWallet #{$this->id} has no asset key or legacy coin");
    }

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
