<?php

declare(strict_types=1);

namespace App\Rules;

use App\Support\Security\WebhookUrlPolicy;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

final class PublicWebhookUrl implements ValidationRule
{
    public function __construct(
        private readonly WebhookUrlPolicy $policy = new WebhookUrlPolicy
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '') {
            return;
        }

        $reason = $this->policy->rejectionReason((string) $value);

        if ($reason !== null) {
            $fail($reason);
        }
    }
}
