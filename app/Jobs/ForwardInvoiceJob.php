<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\InvoiceForwarder;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;

/**
 * Asynchronous settlement trigger for a paid invoice.
 */
class ForwardInvoiceJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public int $uniqueFor = 300;

    /**
     * Create a new job instance.
     *
     * @param  int  $invoiceId  Internal invoice identifier.
     */
    public function __construct(public int $invoiceId)
    {
        //
    }

    public function uniqueId(): string
    {
        return 'invoice-forward:'.$this->invoiceId;
    }

    public function backoff(): array
    {
        return [30, 60, 180, 300];
    }

    /** @return array<int, object> */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping($this->uniqueId()))
                ->releaseAfter(15)
                ->expireAfter(600)
                ->shared(),
        ];
    }

    /**
     * Execute the job.
     */
    public function handle(InvoiceForwarder $forwarder): void
    {
        $forwarder->forward($this->invoiceId);
    }
}
