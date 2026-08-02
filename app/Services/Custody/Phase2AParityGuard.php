<?php

declare(strict_types=1);

namespace App\Services\Custody;

use App\Exceptions\CustodyAccountingException;
use App\Models\MerchantBalance;
use App\Models\MerchantSettlementEntry;
use App\Services\Settlement\SettlementDecimal;
use Brick\Math\BigDecimal;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final readonly class Phase2AParityGuard
{
    public function __construct(private SettlementDecimal $decimal) {}

    /**
     * @param  Collection<int, object>  $lockedProjections
     */
    public function assertBeforeProjectionDelta(
        MerchantSettlementEntry $source,
        MerchantBalance $lockedBalance,
        int $offsetAccountId,
        int $merchantAvailableAccountId,
        Collection $lockedProjections,
    ): void {
        if (DB::transactionLevel() < 1) {
            throw new CustodyAccountingException('Phase 2A parity guard requires an outer transaction.');
        }

        if (
            (int) $lockedBalance->merchant_id !== (int) $source->merchant_id
            || $lockedBalance->coin !== $source->asset_key
        ) {
            throw new CustodyAccountingException('Locked merchant balance does not match Phase 2A source.');
        }

        if (DB::table('custody_journal_source_links')
            ->where('merchant_settlement_entry_id', $source->id)
            ->exists()) {
            throw new CustodyAccountingException('New Phase 2A source was linked before the guarded write.');
        }

        $merchantCovered = $this->coveredTotal(
            merchantId: (int) $source->merchant_id,
            assetKey: $source->asset_key,
            networkKey: (string) $source->network_key,
        );
        $allCovered = $this->coveredTotal(
            merchantId: null,
            assetKey: $source->asset_key,
            networkKey: (string) $source->network_key,
        );
        $balance = $this->decimal->formatExact((string) $lockedBalance->amount, $source->asset_key);
        $merchantProjection = $lockedProjections->get($merchantAvailableAccountId);
        $offsetProjection = $lockedProjections->get($offsetAccountId);

        if ($merchantProjection === null || $offsetProjection === null) {
            throw new CustodyAccountingException('Phase 2A guarded projections are missing.');
        }

        foreach ([
            'merchant balance versus covered source total' => [$balance, $merchantCovered],
            'merchant projection versus covered source total' => [
                (string) $merchantProjection->balance,
                $merchantCovered,
            ],
            'offset projection versus aggregate covered liability' => [
                (string) $offsetProjection->balance,
                $allCovered,
            ],
        ] as $label => [$actual, $expected]) {
            if (BigDecimal::of($actual)->compareTo(BigDecimal::of($expected)) !== 0) {
                throw new CustodyAccountingException("Phase 2A parity guard failed: {$label}.");
            }
        }
    }

    private function coveredTotal(?int $merchantId, string $assetKey, string $networkKey): string
    {
        $row = DB::table('custody_journal_source_links as source_link')
            ->join(
                'merchant_settlement_entries as source',
                'source.id',
                '=',
                'source_link.merchant_settlement_entry_id',
            )
            ->join(
                'custody_journal_entries as journal',
                'journal.id',
                '=',
                'source_link.custody_journal_entry_id',
            )
            ->where('source.type', MerchantSettlementEntry::TYPE_INTERNAL_CREDIT)
            ->where('source.status', MerchantSettlementEntry::STATUS_COMPLETED)
            ->where('source.asset_key', $assetKey)
            ->where('source.network_key', $networkKey)
            ->where('journal.event_type', Phase2AInternalCreditProjector::EVENT_TYPE)
            ->whereNotNull('journal.posted_at')
            ->when($merchantId !== null, fn ($query) => $query->where('source.merchant_id', $merchantId))
            ->selectRaw('COALESCE(SUM(source.amount_coin), 0)::text AS total')
            ->first();

        return (string) ($row->total ?? '0');
    }
}
