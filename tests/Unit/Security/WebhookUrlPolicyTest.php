<?php

declare(strict_types=1);

namespace Tests\Unit\Security;

use App\Support\Security\WebhookUrlPolicy;
use Tests\TestCase;

final class WebhookUrlPolicyTest extends TestCase
{
    public function test_it_rejects_local_private_and_non_http_webhook_urls(): void
    {
        config()->set('webhooks.allow_private_urls', false);

        $policy = new WebhookUrlPolicy;

        foreach ([
            'http://localhost/webhook',
            'http://127.0.0.1/webhook',
            'http://10.0.0.1/webhook',
            'http://[::1]/webhook',
            'ftp://example.test/webhook',
            'https://user:pass@example.test/webhook',
        ] as $url) {
            self::assertNotNull($policy->rejectionReason($url), $url);
        }
    }

    public function test_it_allows_public_http_webhook_urls(): void
    {
        config()->set('webhooks.allow_private_urls', false);

        $policy = new WebhookUrlPolicy;

        self::assertNull($policy->rejectionReason('https://example.test/webhook'));
        self::assertNull($policy->rejectionReason('https://93.184.216.34/webhook'));
    }

    public function test_private_ip_literals_can_be_allowed_for_local_development(): void
    {
        config()->set('webhooks.allow_private_urls', true);

        $policy = new WebhookUrlPolicy;

        self::assertNull($policy->rejectionReason('http://10.0.0.1/webhook'));
        self::assertNotNull($policy->rejectionReason('http://localhost/webhook'));
    }
}
