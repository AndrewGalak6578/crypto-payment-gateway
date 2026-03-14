<?php

namespace App\Jobs;

use App\Models\WebhookDelivery;
use App\Services\Webhooks\WebhookDeliverySender;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class DeliverWebhookJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $uniqueFor = 120;

    /**
     * Create a new job instance.
     */
    public function __construct(public int $deliveryId)
    {
        //
    }

    public function uniqueId(): string
    {
        return 'webhook-delivery:' . $this->deliveryId;
    }

    /**
     * Execute the job.
     */
    public function handle(WebhookDeliverySender $sender): void
    {
        $sender->send($this->deliveryId);
    }
}
