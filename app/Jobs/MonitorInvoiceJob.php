<?php
declare(strict_types=1);

namespace App\Jobs;

use App\Models\Invoice;
use App\Services\InvoiceStatusRefresher;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;

/**
 * Periodically refreshes invoice chain state until terminal status.
 */
class MonitorInvoiceJob implements ShouldQueue
{
    use Queueable, Dispatchable, InteractsWithQueue, SerializesModels;

    /**
     * Create a new job instance.
     *
     * @param int $invoiceId Internal invoice identifier.
     */
    public function __construct(public int $invoiceId) {}

    /**
     * Execute the job.
     *
     * @param InvoiceStatusRefresher $refresher
     */
    public function handle(InvoiceStatusRefresher $refresher): void
    {
        $inv = Invoice::query()->find($this->invoiceId);
        if (!$inv) return;

        if (in_array($inv->status, ['paid', 'expired'], true)) return;

        /** @var Carbon $now */
        $now = now('UTC');
        if ($inv->monitor_until && $now->gt($inv->monitor_until)) return;

        $inv = $refresher->refresh($inv);

        if (in_array($inv->status, ['paid', 'expired'], true)) return;

        $delay = $this->nextDelaySeconds($inv, $now);

        self::dispatch($inv->id)->delay($now->copy()->addSeconds($delay));
    }

    /**
     * Selects polling cadence based on invoice age and expiration.
     *
     * @param Invoice $inv
     * @param Carbon $nowUtc
     * @return int Delay in seconds.
     */
    private function nextDelaySeconds(Invoice $inv, Carbon $nowUtc): int
    {
        $fastSec = (int)config('payments.monitor.poll_fast_sec', 60);
        $slowSec = (int)config('payments.monitor.poll_slow_sec', 300);
        $fastPhaseMinutes = (int)config('payments.monitor.fast_phase_minutes', 30);

        // safety
        $fastSec = max(5, $fastSec);
        $slowSec = max($fastSec, $slowSec);
        $fastPhaseMinutes = max(1, $fastPhaseMinutes);

        /** @var Carbon|null $createdAtUtc */
        $createdAtUtc = $inv->created_at?->copy()->utc();
        if (!$createdAtUtc) return $slowSec;

        // after expires_at we can go straight to slow (for less touching rpc)
        if ($inv->expires_at && $nowUtc->gt($inv->expires_at->copy()->utc())) {
            return $slowSec;
        }

        $ageMinutes = $createdAtUtc->diffInMinutes($nowUtc);

        return $ageMinutes < $fastPhaseMinutes ? $fastSec : $slowSec;
    }
}
