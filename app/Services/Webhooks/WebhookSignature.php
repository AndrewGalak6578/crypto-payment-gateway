<?php
declare(strict_types=1);

namespace App\Services\Webhooks;

final class WebhookSignature
{
    public function sign(string $payloadJson, string $secret): string
    {
        return hash_hmac('sha256', $payloadJson, $secret);
    }
}
