<?php

declare(strict_types=1);

namespace Tests\Feature\Custody;

use App\Models\Merchant;
use App\Models\MerchantSettlementEntry;
use App\Services\Custody\LegacyMerchantBalanceReconciler;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class LegacyMerchantBalanceReconcilerTest extends TestCase
{
    use DatabaseMigrations;

    public function test_reports_every_legacy_classification_and_excludes_held_entries_without_mutation(): void
    {
        $exact = $this->scope('btc', '2.00000000', '2.00000000');
        $balanceOnly = $this->scope('btc', '1.00000000');
        $creditOnly = $this->scope('btc', null, '1.00000000');
        $balanceGreater = $this->scope('btc', '3.00000000', '2.00000000');
        $creditGreater = $this->scope('btc', '1.00000000', '2.00000000');
        $unknown = $this->scope('mystery', '1.00000000');
        $networkMismatch = $this->scope('btc', '1.00000000', '1.00000000', 'litecoin');
        $negativeBalance = $this->scope('btc', '-1.00000000');
        $negativeCredit = $this->scope('btc', '0', '-1.00000000');
        $heldOnly = $this->scope('btc', '5.00000000');
        $this->settlementEntry(
            $heldOnly,
            'btc',
            'bitcoin',
            '5.00000000',
            MerchantSettlementEntry::TYPE_FORWARD_HELD,
            MerchantSettlementEntry::STATUS_DEFERRED,
        );

        $beforeBalances = DB::table('merchant_balances')->orderBy('id')->get()->toJson();
        $beforeEntries = DB::table('merchant_settlement_entries')->orderBy('id')->get()->toJson();
        $result = app(LegacyMerchantBalanceReconciler::class)->reconcile();
        $rows = collect($result['rows'])->keyBy('merchant_id');

        self::assertSame(LegacyMerchantBalanceReconciler::EXACT, $rows[$exact->id]['classification']);
        self::assertSame(
            LegacyMerchantBalanceReconciler::BALANCE_WITHOUT_CREDIT,
            $rows[$balanceOnly->id]['classification'],
        );
        self::assertSame(
            LegacyMerchantBalanceReconciler::CREDIT_WITHOUT_BALANCE,
            $rows[$creditOnly->id]['classification'],
        );
        self::assertSame(
            LegacyMerchantBalanceReconciler::BALANCE_EXCEEDS_CREDIT,
            $rows[$balanceGreater->id]['classification'],
        );
        self::assertSame(
            LegacyMerchantBalanceReconciler::CREDIT_EXCEEDS_BALANCE,
            $rows[$creditGreater->id]['classification'],
        );
        self::assertSame(LegacyMerchantBalanceReconciler::UNKNOWN_ASSET, $rows[$unknown->id]['classification']);
        self::assertSame(
            LegacyMerchantBalanceReconciler::NETWORK_MISMATCH,
            $rows[$networkMismatch->id]['classification'],
        );
        self::assertSame(
            LegacyMerchantBalanceReconciler::NEGATIVE_BALANCE,
            $rows[$negativeBalance->id]['classification'],
        );
        self::assertSame(
            LegacyMerchantBalanceReconciler::NEGATIVE_CREDIT,
            $rows[$negativeCredit->id]['classification'],
        );
        self::assertSame(
            LegacyMerchantBalanceReconciler::BALANCE_WITHOUT_CREDIT,
            $rows[$heldOnly->id]['classification'],
        );
        self::assertSame('0.000000000000000000', $rows[$heldOnly->id]['completed_internal_credit_total']);
        self::assertSame(9, $result['mismatch_count']);
        self::assertSame($beforeBalances, DB::table('merchant_balances')->orderBy('id')->get()->toJson());
        self::assertSame($beforeEntries, DB::table('merchant_settlement_entries')->orderBy('id')->get()->toJson());
    }

    public function test_filters_and_json_command_output_are_read_only(): void
    {
        $included = $this->scope('btc', '10.00000001', '10.00000001', 'bitcoin');
        $this->scope('ltc', '2.00000000', '1.00000000');
        $unknown = $this->scope('mystery', '1.00000000');
        $before = DB::table('merchant_balances')->orderBy('id')->get()->toJson();

        $this->artisan('custody:reconcile-legacy-balances', [
            '--merchant' => (string) $included->id,
            '--asset' => 'btc',
            '--json' => true,
        ])->assertSuccessful()->expectsOutputToContain('"classification": "exact_match"');

        $this->artisan('custody:reconcile-legacy-balances', [
            '--merchant' => (string) $unknown->id,
            '--asset' => 'mystery',
            '--json' => true,
        ])->assertSuccessful()->expectsOutputToContain('"classification": "unknown_asset"');

        self::assertSame($before, DB::table('merchant_balances')->orderBy('id')->get()->toJson());
    }

    private function scope(
        string $assetKey,
        ?string $balance,
        ?string $credit = null,
        ?string $networkKey = null,
    ): Merchant {
        $merchant = Merchant::query()->create([
            'name' => "Legacy {$assetKey} ".uniqid(),
            'status' => 'active',
            'fee_percent' => '1.000',
        ]);

        if ($balance !== null) {
            DB::table('merchant_balances')->insert([
                'merchant_id' => $merchant->id,
                'coin' => $assetKey,
                'amount' => $balance,
                'created_at' => now('UTC'),
                'updated_at' => now('UTC'),
            ]);
        }

        if ($credit !== null) {
            $this->settlementEntry(
                $merchant,
                $assetKey,
                $networkKey ?? match ($assetKey) {
                    'btc' => 'bitcoin',
                    'eth_usdt_local' => 'evm_local',
                    default => null,
                },
                $credit,
                MerchantSettlementEntry::TYPE_INTERNAL_CREDIT,
                MerchantSettlementEntry::STATUS_COMPLETED,
            );
        }

        return $merchant;
    }

    private function settlementEntry(
        Merchant $merchant,
        string $assetKey,
        ?string $networkKey,
        string $amount,
        string $type,
        string $status,
    ): void {
        DB::table('merchant_settlement_entries')->insert([
            'merchant_id' => $merchant->id,
            'invoice_id' => null,
            'settlement_attempt_id' => null,
            'asset_key' => $assetKey,
            'network_key' => $networkKey,
            'type' => $type,
            'status' => $status,
            'amount_coin' => $amount,
            'fee_coin' => null,
            'amount_usd' => null,
            'destination_wallet' => null,
            'txid' => null,
            'idempotency_key' => 'legacy:test:'.uniqid('', true),
            'error_message' => null,
            'metadata' => null,
            'occurred_at' => now('UTC'),
            'created_at' => now('UTC'),
            'updated_at' => now('UTC'),
        ]);
    }
}
