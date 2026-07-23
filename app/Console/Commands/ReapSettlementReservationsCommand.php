<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Settlement\MerchantSettlementAttemptManager;
use Illuminate\Console\Command;

final class ReapSettlementReservationsCommand extends Command
{
    protected $signature = 'settlements:reap-reservations {--limit=100}';

    protected $description = 'Fail expired pre-broadcast settlement reservations as retry-safe';

    public function handle(MerchantSettlementAttemptManager $attempts): int
    {
        $count = $attempts->reapExpiredReservations(max(1, (int) $this->option('limit')));
        $this->info("Released {$count} expired settlement reservation(s).");

        return self::SUCCESS;
    }
}
