<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Merchant aggregate root for API auth, invoice ownership and settlement settings.
 *
 * @property int $id
 * @property string $name
 * @property string|null $status
 * @property float|null $fee_percent
 * @property string|null $webhook_url
 * @property string|null $webhook_secret
 *
 * @property-read Collection<int, MerchantApiKey> $apiKeys
 * @property-read Collection<int, Invoice> $invoices
 * @property-read Collection<int, SuperWallet> $superWallets
 * @property-read Collection<int, MerchantBalance> $balances
 * @property-read Collection<int, MerchantUser> $users
 */
class Merchant extends Model
{
    protected $fillable = [
        'name', 'status', 'fee_percent', 'webhook_url', 'webhook_secret'
    ];

    public function apiKeys(): HasMany
    {
        return $this->hasMany(MerchantApiKey::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function superWallets(): HasMany
    {
        return $this->hasMany(SuperWallet::class);
    }

    public function balances(): HasMany
    {
        return $this->hasMany(MerchantBalance::class);
    }

    public function users(): HasMany
    {
        return $this->hasMany(MerchantUser::class);
    }
}
