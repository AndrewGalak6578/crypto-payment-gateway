<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ParsesCustodyCommandFilters;
use App\Services\Custody\CustodyProjectionRebuilder;
use App\Support\Assets\AssetRegistry;
use Illuminate\Console\Command;
use InvalidArgumentException;

final class RebuildCustodyProjectionsCommand extends Command
{
    use ParsesCustodyCommandFilters;

    protected $signature = 'custody:rebuild-projections
        {--merchant= : Limit rebuild to a merchant ID}
        {--asset= : Limit rebuild to an asset key}
        {--write : Apply the rebuilt projections}
        {--json : Emit machine-readable JSON}';

    protected $description = 'Rebuild custody projections from posted journal history; dry-run by default';

    public function handle(CustodyProjectionRebuilder $rebuilder, AssetRegistry $assets): int
    {
        $write = (bool) $this->option('write');
        try {
            $merchantId = $this->positiveMerchantOption();
            $assetKey = $this->registeredAssetOption($assets);
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::INVALID;
        }

        $result = $rebuilder->rebuild($write, $merchantId, $assetKey);

        if ((bool) $this->option('json')) {
            $this->line(json_encode($result, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
        } else {
            $before = $result['before'];
            $after = $result['after'];
            $this->line(sprintf(
                '%s: checked %d account(s); drift before: %d; drift after: %d.',
                $write ? 'Write rebuild' : 'Dry run',
                $before['checked'],
                $before['drift_count'],
                $after['drift_count'],
            ));
        }

        return $result['after']['drift_count'] === 0 ? self::SUCCESS : self::FAILURE;
    }
}
