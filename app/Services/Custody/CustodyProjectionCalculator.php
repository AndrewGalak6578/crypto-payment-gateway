<?php

declare(strict_types=1);

namespace App\Services\Custody;

use App\Exceptions\CustodyAccountingException;
use App\Models\CustodyAccount;
use Brick\Math\BigDecimal;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final readonly class CustodyProjectionCalculator
{
    public function __construct(private CustodyDecimal $decimal) {}

    /**
     * @param  Collection<int, CustodyAccount>  $accounts
     * @return array<int, array{balance: string, revision: int, last_journal_entry_id: ?int}>
     */
    public function calculate(Collection $accounts): array
    {
        if ($accounts->isEmpty()) {
            return [];
        }

        $states = [];
        foreach ($accounts as $account) {
            $states[$account->id] = [
                'decimal' => BigDecimal::zero(),
                'revision' => 0,
                'last_journal_entry_id' => null,
            ];
        }

        $rows = DB::table('custody_journal_postings as p')
            ->join('custody_journal_entries as e', 'e.id', '=', 'p.journal_entry_id')
            ->whereNotNull('e.posted_at')
            ->whereIn('p.account_id', $accounts->modelKeys())
            ->orderBy('p.account_id')
            ->orderBy('e.id')
            ->orderBy('p.line_number')
            ->get([
                'p.account_id',
                'p.side',
                'p.amount',
                'e.id as journal_entry_id',
            ]);

        $accountMap = $accounts->keyBy('id');
        $entryDeltas = [];
        foreach ($rows as $row) {
            $account = $accountMap->get((int) $row->account_id);
            if ($account === null) {
                throw new CustodyAccountingException('Projection calculation found an unknown account.');
            }

            $accountId = (int) $row->account_id;
            $entryId = (int) $row->journal_entry_id;
            $amount = BigDecimal::of((string) $row->amount);
            $signed = $row->side === $account->normal_side ? $amount : $amount->negated();
            $entryDeltas[$accountId][$entryId] = isset($entryDeltas[$accountId][$entryId])
                ? $entryDeltas[$accountId][$entryId]->plus($signed)
                : $signed;
        }

        foreach ($entryDeltas as $accountId => $deltas) {
            ksort($deltas, SORT_NUMERIC);
            foreach ($deltas as $entryId => $delta) {
                $states[$accountId]['decimal'] = $states[$accountId]['decimal']->plus($delta);
                if ($states[$accountId]['decimal']->isNegative()) {
                    throw new CustodyAccountingException(
                        "Posted history makes controlled custody account [{$accountId}] negative.",
                    );
                }

                $states[$accountId]['revision']++;
                $states[$accountId]['last_journal_entry_id'] = $entryId;
            }
        }

        return array_map(fn (array $state): array => [
            'balance' => $this->decimal->storage($state['decimal']),
            'revision' => $state['revision'],
            'last_journal_entry_id' => $state['last_journal_entry_id'],
        ], $states);
    }
}
