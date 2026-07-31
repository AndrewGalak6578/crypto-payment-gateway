<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ParsesCustodyCommandFilters;
use App\Services\Custody\CustodyProjectionVerifier;
use App\Support\Assets\AssetRegistry;
use Illuminate\Console\Command;
use InvalidArgumentException;

final class VerifyCustodyProjectionsCommand extends Command
{
    use ParsesCustodyCommandFilters;

    protected $signature = 'custody:verify-projections
        {--merchant= : Limit verification to a merchant ID}
        {--asset= : Limit verification to an asset key}
        {--json : Emit machine-readable JSON}';

    protected $description = 'Read-only verification of custody balance projections against posted journal history';

    public function handle(CustodyProjectionVerifier $verifier, AssetRegistry $assets): int
    {
        try {
            $merchantId = $this->positiveMerchantOption();
            $assetKey = $this->registeredAssetOption($assets);
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::INVALID;
        }

        $result = $verifier->verify($merchantId, $assetKey);

        if ((bool) $this->option('json')) {
            $this->line(json_encode($result, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
        } else {
            $drift = array_values(array_filter($result['rows'], fn (array $row): bool => $row['drift']));
            if ($drift !== []) {
                $this->table(
                    ['Account', 'Scope', 'Asset/Network', 'Expected', 'Actual', 'Expected rev', 'Actual rev'],
                    array_map(fn (array $row): array => [
                        $row['account_id'],
                        $row['scope_key'],
                        "{$row['asset_key']}/{$row['network_key']}",
                        $row['expected_balance'],
                        $row['actual_balance'] ?? 'missing',
                        $row['expected_revision'],
                        $row['actual_revision'] ?? 'missing',
                    ], $drift),
                );
            }
            $this->line("Checked {$result['checked']} account(s); drift: {$result['drift_count']}.");
        }

        return $result['drift_count'] === 0 ? self::SUCCESS : self::FAILURE;
    }
}
