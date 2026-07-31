<?php

declare(strict_types=1);

namespace App\Services\Custody;

use App\Models\CustodyAccount;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

final readonly class CustodyProjectionVerifier
{
    public function __construct(private CustodyProjectionCalculator $calculator) {}

    public function verify(?int $merchantId = null, ?string $assetKey = null): array
    {
        if (DB::transactionLevel() > 0) {
            return $this->verifySnapshot($merchantId, $assetKey);
        }

        return DB::transaction(function () use ($merchantId, $assetKey): array {
            DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');

            return $this->verifySnapshot($merchantId, $assetKey);
        });
    }

    /**
     * @return array{
     *   checked: int,
     *   drift_count: int,
     *   rows: list<array<string, int|string|null|bool>>
     * }
     */
    private function verifySnapshot(?int $merchantId, ?string $assetKey): array
    {
        $accounts = $this->accountQuery($merchantId, $assetKey)->orderBy('id')->get();
        $expected = $this->calculator->calculate($accounts);
        $actual = DB::table('custody_account_balances')
            ->whereIn('account_id', $accounts->modelKeys())
            ->get()
            ->keyBy('account_id');
        $rows = [];

        foreach ($accounts as $account) {
            $projection = $actual->get($account->id);
            $expectedState = $expected[$account->id];
            $actualBalance = $projection === null ? null : (string) $projection->balance;
            $actualRevision = $projection === null ? null : (int) $projection->revision;
            $actualLastEntry = $projection?->last_journal_entry_id === null
                ? null
                : (int) $projection->last_journal_entry_id;
            $drift = $projection === null
                || $actualBalance !== $expectedState['balance']
                || $actualRevision !== $expectedState['revision']
                || $actualLastEntry !== $expectedState['last_journal_entry_id'];

            $rows[] = [
                'account_id' => $account->id,
                'account_uuid' => $account->account_uuid,
                'scope_key' => $account->scope_key,
                'merchant_id' => $account->merchant_id,
                'asset_key' => $account->asset_key,
                'network_key' => $account->network_key,
                'account_code' => $account->account_code,
                'expected_balance' => $expectedState['balance'],
                'actual_balance' => $actualBalance,
                'expected_revision' => $expectedState['revision'],
                'actual_revision' => $actualRevision,
                'expected_last_journal_entry_id' => $expectedState['last_journal_entry_id'],
                'actual_last_journal_entry_id' => $actualLastEntry,
                'drift' => $drift,
            ];
        }

        return [
            'checked' => count($rows),
            'drift_count' => count(array_filter($rows, fn (array $row): bool => $row['drift'])),
            'rows' => $rows,
        ];
    }

    public function accountQuery(?int $merchantId = null, ?string $assetKey = null): Builder
    {
        return CustodyAccount::query()
            ->when($merchantId !== null, fn (Builder $query) => $query->where('merchant_id', $merchantId))
            ->when(
                $assetKey !== null && $assetKey !== '',
                fn (Builder $query) => $query->where('asset_key', strtolower($assetKey)),
            );
    }
}
