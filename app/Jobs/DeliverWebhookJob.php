<?php

namespace App\Jobs;

use App\Services\Webhooks\WebhookDeliverySender;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Delivers a single webhook attempt for stored webhook delivery record.
 */
class DeliverWebhookJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $uniqueFor = 120;

    /**
     * Create a new job instance.
     *
     * @param int $deliveryId Webhook delivery record identifier.
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
     *
     * @param WebhookDeliverySender $sender
     */
    public function handle(WebhookDeliverySender $sender): void
    {
        $sender->send($this->deliveryId);
    }
}
