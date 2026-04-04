<?php
declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Invoice;
use App\Models\SuperWallet;
use App\Support\Assets\AssetRegistry;
use Illuminate\Console\Command;

class BackfillAssetKeys extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:backfill-asset-keys {--dry-run}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Backfill network_key and asset_key from legacy coin values';

    /**
     * Execute the console command.
     */
    public function handle(AssetRegistry $assets): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $this->info('Backfilling invoices...');
        Invoice::query()
            ->whereNull('asset_key')
            ->whereNotNull('coin')
            ->chunkById(500, function ($rows) use ($assets, $dryRun): void {
                foreach ($rows as $invoice) {
                    $coin = strtolower((string) $invoice->coin);

                    if (!$assets->exists($coin, false)) {
                        $this->warn("Invoice #{$invoice->id}: unknown coin [{$coin}]");
                        continue;
                    }

                    $asset = $assets->get($coin);

                    $this->line("Invoice #{$invoice->id}: {$coin} => {$asset['network']} / {$coin}");

                    if (!$dryRun) {
                        $invoice->forceFill([
                            'asset_key' => $coin,
                            'network_key' => $asset['network'],
                        ])->save();
                    }
                }
            });

        $this->info('Backfilling invoices complete!!!');
        $this->info('Backfilling SuperWallets...');
        SuperWallet::query()
            ->whereNull('asset_key')
            ->whereNotNull('coin')
            ->chunkById(500, function ($rows) use ($assets, $dryRun): void {
                foreach ($rows as $wallet) {
                    $coin = strtolower((string) $wallet->coin);

                    if (!$assets->exists($coin, false)) {
                        $this->warn("SuperWallet #{$wallet->id}: unknown coin [{$coin}]");
                        continue;
                    }

                    $asset = $assets->get($coin);

                    $this->line("SuperWallet #{$wallet->id}: {$coin} => {$asset['network']} / {$coin}");

                    if (!$dryRun) {
                        $wallet->forceFill([
                            'asset_key' => $coin,
                            'network_key' => $asset['network'],
                        ])->save();
                    }
                }
            });

        $this->info('Backfilling SuperWallets complete!!!');
        $this->info('Done.');

        return self::SUCCESS;
    }
}
