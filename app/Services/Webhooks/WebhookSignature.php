<?php
declare(strict_types=1);

namespace App\Services\Webhooks;

/**
 * Signs outgoing webhook payloads with merchant secret.
 */
final class WebhookSignature
{
    /**
     * @param string $payloadJson Canonical JSON payload.
     * @param string $secret Merchant webhook secret.
     * @return string Hex-encoded HMAC SHA-256 signature.
     */
    public function sign(string $payloadJson, string $secret): string
    {
        return hash_hmac('sha256', $payloadJson, $secret);
    }
}
