<?php
declare(strict_types=1);

namespace App\Services\Webhooks;

use App\Models\Invoice;

final class InvoiceWebhookPayloadFactory
{
    public function make(string $event, Invoice $invoice): array
    {
        return [
            'event' => $event,
            'sent_at' => now('UTC')->toIso8601String(),
            'invoice' => [
                'id' => $invoice->id,
                'public_id' => $invoice->public_id,
                'external_id' => $invoice->external_id,
                'status' => $invoice->status,
                'coin' => $invoice->coin,
                'pay_address' => $invoice->pay_address,
                'amount_coin' => (string) $invoice->amount_coin,
                'expected_usd' => (string) $invoice->expected_usd,
                'rate_usd' => (string) $invoice->rate_usd,
                'received_conf_coin' => (string) $invoice->received_conf_coin,
                'received_all_coin' => (string) $invoice->received_all_coin,
                'forward_status' => $invoice->forward_status,
                'forwarded_coin' => (string) $invoice->forwarded_coin,
                'forward_txids' => $invoice->forward_txids ?? [],
                'expires_at' => optional($invoice->expires_at)->toIso8601String(),
                'fixated_at' => optional($invoice->fixated_at)->toIso8601String(),
                'paid_at' => optional($invoice->paid_at)->toIso8601String(),
                'last_forwarded_at' => optional($invoice->last_forwarded_at)->toIso8601String(),
                'metadata' => $invoice->metadata ?? [],
            ]
        ];
    }
}
