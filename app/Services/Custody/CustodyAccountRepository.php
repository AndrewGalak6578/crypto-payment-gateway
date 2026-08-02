<?php

declare(strict_types=1);

namespace App\Services\Custody;

use App\Exceptions\CustodyAccountingException;
use App\Models\CustodyAccount;
use App\Support\Assets\AssetRegistry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final readonly class CustodyAccountRepository
{
    private const MERCHANT_CODES = [
        CustodyAccount::CODE_MERCHANT_AVAILABLE,
        CustodyAccount::CODE_MERCHANT_RESERVED,
        CustodyAccount::CODE_MERCHANT_HELD,
    ];

    private const PLATFORM_CODES = [
        CustodyAccount::CODE_DEPOSIT_UNCOLLECTED,
        CustodyAccount::CODE_TREASURY_AVAILABLE,
        CustodyAccount::CODE_TREASURY_RESERVED,
        CustodyAccount::CODE_OUTBOUND,
        CustodyAccount::CODE_FEE_REVENUE,
        CustodyAccount::CODE_NETWORK_FEE_EXPENSE,
        CustodyAccount::CODE_INTERNAL_CREDIT_SHADOW_OFFSET,
    ];

    public function __construct(private AssetRegistry $assets) {}

    public function merchant(
        int $merchantId,
        string $assetKey,
        string $networkKey,
        string $accountCode,
    ): CustodyAccount {
        if ($merchantId <= 0 || ! in_array($accountCode, self::MERCHANT_CODES, true)) {
            throw new CustodyAccountingException('Invalid merchant custody account ownership.');
        }

        return $this->findOrCreate(
            scopeKey: "merchant:{$merchantId}",
            merchantId: $merchantId,
            assetKey: $assetKey,
            networkKey: $networkKey,
            accountCode: $accountCode,
            normalSide: CustodyAccount::SIDE_CREDIT,
        );
    }

    public function platform(string $assetKey, string $networkKey, string $accountCode): CustodyAccount
    {
        if (! in_array($accountCode, self::PLATFORM_CODES, true)) {
            throw new CustodyAccountingException('Invalid platform custody account code.');
        }

        return $this->findOrCreate(
            scopeKey: 'platform',
            merchantId: null,
            assetKey: $assetKey,
            networkKey: $networkKey,
            accountCode: $accountCode,
            normalSide: $accountCode === CustodyAccount::CODE_FEE_REVENUE
                ? CustodyAccount::SIDE_CREDIT
                : CustodyAccount::SIDE_DEBIT,
        );
    }

    public function migrationSuspense(string $assetKey, string $networkKey): CustodyAccount
    {
        return $this->findOrCreate(
            scopeKey: 'migration',
            merchantId: null,
            assetKey: $assetKey,
            networkKey: $networkKey,
            accountCode: CustodyAccount::CODE_MIGRATION_SUSPENSE,
            normalSide: CustodyAccount::SIDE_DEBIT,
        );
    }

    private function findOrCreate(
        string $scopeKey,
        ?int $merchantId,
        string $assetKey,
        string $networkKey,
        string $accountCode,
        string $normalSide,
    ): CustodyAccount {
        $this->assertAccountingEnabled();
        $assetKey = strtolower(trim($assetKey));
        $asset = $this->assets->get($assetKey);

        if ((string) $asset['network'] !== $networkKey) {
            throw new CustodyAccountingException('Custody account network does not match the asset registry.');
        }

        $scale = $this->assets->settlementScale($assetKey);

        return DB::transaction(function () use (
            $scopeKey,
            $merchantId,
            $assetKey,
            $networkKey,
            $accountCode,
            $normalSide,
            $scale,
        ): CustodyAccount {
            CustodyAccount::query()->insertOrIgnore([
                'account_uuid' => (string) Str::uuid(),
                'scope_key' => $scopeKey,
                'merchant_id' => $merchantId,
                'asset_key' => $assetKey,
                'network_key' => $networkKey,
                'asset_scale' => $scale,
                'account_code' => $accountCode,
                'normal_side' => $normalSide,
                'created_at' => now('UTC'),
            ]);

            /** @var CustodyAccount $account */
            $account = CustodyAccount::query()
                ->where('scope_key', $scopeKey)
                ->where('asset_key', $assetKey)
                ->where('network_key', $networkKey)
                ->where('account_code', $accountCode)
                ->firstOrFail();

            DB::table('custody_account_balances')->insertOrIgnore([
                'account_id' => $account->id,
                'balance' => '0',
                'last_journal_entry_id' => null,
                'revision' => 0,
                'rebuilt_at' => null,
                'created_at' => now('UTC'),
                'updated_at' => now('UTC'),
            ]);

            return $account;
        });
    }

    private function assertAccountingEnabled(): void
    {
        if (config('custody.accounting_enabled', false) !== true) {
            throw new CustodyAccountingException('Custody accounting is disabled.');
        }
    }
}
