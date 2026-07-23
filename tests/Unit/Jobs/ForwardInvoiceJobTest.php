<?php

declare(strict_types=1);

namespace Tests\Unit\Jobs;

use App\Jobs\ForwardInvoiceJob;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

final class ForwardInvoiceJobTest extends TestCase
{
    public function test_duplicate_dispatch_is_unique_per_invoice_while_queued(): void
    {
        Queue::fake();

        ForwardInvoiceJob::dispatch(73);
        ForwardInvoiceJob::dispatch(73);
        ForwardInvoiceJob::dispatch(74);

        Queue::assertPushed(ForwardInvoiceJob::class, 2);
        self::assertInstanceOf(ShouldBeUniqueUntilProcessing::class, new ForwardInvoiceJob(73));
    }

    public function test_invoice_overlap_lock_releases_duplicate_without_entering_handler(): void
    {
        $first = new ForwardInvoiceJob(73);
        $middleware = $first->middleware()[0];
        self::assertInstanceOf(WithoutOverlapping::class, $middleware);

        $lock = Cache::lock($middleware->getLockKey($first), 60);
        self::assertTrue($lock->get());

        try {
            $duplicate = (new ForwardInvoiceJob(73))->withFakeQueueInteractions();
            $entered = false;

            $duplicate->middleware()[0]->handle(
                $duplicate,
                function () use (&$entered): void {
                    $entered = true;
                },
            );

            self::assertFalse($entered);
            $duplicate->assertReleased(15);
        } finally {
            $lock->release();
        }
    }
}
