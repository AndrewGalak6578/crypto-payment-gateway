<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ParsesCustodyCommandFilters;
use App\Services\Custody\LegacyMerchantBalanceReconciler;
use Illuminate\Console\Command;
use InvalidArgumentException;

final class ReconcileLegacyCustodyBalancesCommand extends Command
{
    use ParsesCustodyCommandFilters;

    protected $signature = 'custody:reconcile-legacy-balances
        {--merchant= : Limit comparison to a merchant ID}
        {--asset= : Limit comparison to an asset key}
        {--json : Emit machine-readable JSON}';

    protected $description = 'Read-only comparison of merchant balances with completed internal-credit ledger entries';

    public function handle(LegacyMerchantBalanceReconciler $reconciler): int
    {
        try {
            $merchantId = $this->positiveMerchantOption();
            $assetKey = $this->assetOption();
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::INVALID;
        }

        $result = $reconciler->reconcile($merchantId, $assetKey);

        if ((bool) $this->option('json')) {
            $this->line(json_encode($result, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
        } else {
            $this->table(
                ['Merchant', 'Asset', 'Balance', 'Internal credits', 'Classification'],
                array_map(fn (array $row): array => [
                    $row['merchant_id'],
                    $row['asset_key'],
                    $row['merchant_balance'],
                    $row['completed_internal_credit_total'],
                    $row['classification'],
                ], $result['rows']),
            );
            $this->line("Checked {$result['checked']} balance scope(s); mismatches: {$result['mismatch_count']}.");
        }

        return self::SUCCESS;
    }
}
