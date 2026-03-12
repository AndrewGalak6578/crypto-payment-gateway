<?php

namespace App\Jobs;

use App\Services\InvoiceForwarder;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ForwardInvoiceJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;
    public int $uniqueFor = 120;

    /**
     * Create a new job instance.
     */
    public function __construct(public int $invoiceId)
    {
        //
    }

    public function uniqueId(): string
    {
        return 'invoice-forward:' . $this->invoiceId;
    }

    public function backoff(): array
    {
        return [30, 60, 180, 300];
    }

    /**
     * Execute the job.
     */
    public function handle(InvoiceForwarder $forwarder): void
    {
        $forwarder->forward($this->invoiceId);
    }
}
