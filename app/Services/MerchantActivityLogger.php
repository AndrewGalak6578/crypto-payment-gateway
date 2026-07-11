<?php
declare(strict_types=1);

namespace App\Services;

use App\Models\MerchantActivityLog;
use App\Models\MerchantUser;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;

final class MerchantActivityLogger
{
    /**
     * @param array<string, mixed> $metadata
     */
    public function log(Request $request, string $section, string $action, array $metadata = [], array $context = []): MerchantActivityLog
    {
        /** @var MerchantUser|null $actor */
        $actor = $request->attributes->get('merchant_user');
        $merchantId = (int) ($context['merchant_id'] ?? $actor?->merchant_id);

        return MerchantActivityLog::query()->create([
            'merchant_id' => $merchantId,
            'actor_merchant_user_id' => $context['actor_merchant_user_id'] ?? $actor?->id,
            'subject_merchant_user_id' => $context['subject_merchant_user_id'] ?? null,
            'section' => $section,
            'type' => $context['type'] ?? 'action',
            'action' => $action,
            'target_type' => $context['target_type'] ?? null,
            'target_id' => isset($context['target_id']) ? (string) $context['target_id'] : null,
            'target_label' => $context['target_label'] ?? null,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'metadata' => $this->cleanMetadata($metadata),
        ]);
    }

    /**
     * @param array<string, mixed> $metadata
     * @return array<string, mixed>
     */
    private function cleanMetadata(array $metadata): array
    {
        return Arr::except($metadata, [
            'password',
            'password_confirmation',
            'token',
            'plain_token',
            'webhook_secret',
            'secret',
        ]);
    }
}
