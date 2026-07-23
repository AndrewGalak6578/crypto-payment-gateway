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
 * @property string|null $checkout_display_name
 * @property string|null $checkout_support_email
 * @property string|null $checkout_brand_color
 * @property int|null $checkout_expires_minutes
 * @property bool $checkout_payer_can_choose_asset
 * @property string|null $checkout_default_asset
 * @property array<int, string>|null $checkout_allowed_assets
 * @property string|null $checkout_success_url
 * @property string|null $checkout_cancel_url
 * @property bool $checkout_auto_redirect
 * @property int $checkout_redirect_delay_seconds
 * @property bool $checkout_show_invoice_id
 * @property bool $checkout_show_support_email
 * @property string $checkout_partial_payment_policy
 * @property string $checkout_confirmation_display
 * @property float|null $checkout_min_amount_usd
 * @property float|null $checkout_max_amount_usd
 * @property-read Collection<int, MerchantApiKey> $apiKeys
 * @property-read Collection<int, Invoice> $invoices
 * @property-read Collection<int, SuperWallet> $superWallets
 * @property-read Collection<int, MerchantBalance> $balances
 * @property-read Collection<int, MerchantUser> $users
 * @property-read Collection<int, MerchantActivityLog> $activityLogs
 * @property-read Collection<int, MerchantAssetPolicy> $assetPolicies
 */
class Merchant extends Model
{
    protected $fillable = [
        'name',
        'status',
        'fee_percent',
        'webhook_url',
        'webhook_secret',
        'checkout_display_name',
        'checkout_support_email',
        'checkout_brand_color',
        'checkout_expires_minutes',
        'checkout_payer_can_choose_asset',
        'checkout_default_asset',
        'checkout_allowed_assets',
        'checkout_success_url',
        'checkout_cancel_url',
        'checkout_auto_redirect',
        'checkout_redirect_delay_seconds',
        'checkout_show_invoice_id',
        'checkout_show_support_email',
        'checkout_partial_payment_policy',
        'checkout_confirmation_display',
        'checkout_min_amount_usd',
        'checkout_max_amount_usd',
    ];

    protected $casts = [
        'fee_percent' => 'float',
        'checkout_expires_minutes' => 'integer',
        'checkout_payer_can_choose_asset' => 'boolean',
        'checkout_allowed_assets' => 'array',
        'checkout_auto_redirect' => 'boolean',
        'checkout_redirect_delay_seconds' => 'integer',
        'checkout_show_invoice_id' => 'boolean',
        'checkout_show_support_email' => 'boolean',
        'checkout_min_amount_usd' => 'float',
        'checkout_max_amount_usd' => 'float',
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

    public function activityLogs(): HasMany
    {
        return $this->hasMany(MerchantActivityLog::class);
    }

    public function paymentAddresses(): HasMany
    {
        return $this->hasMany(PaymentAddress::class);
    }

    public function assetPolicies(): HasMany
    {
        return $this->hasMany(MerchantAssetPolicy::class);
    }

    public function settlementAttempts(): HasMany
    {
        return $this->hasMany(MerchantSettlementAttempt::class);
    }

    public function settlementEntries(): HasMany
    {
        return $this->hasMany(MerchantSettlementEntry::class);
    }
}
