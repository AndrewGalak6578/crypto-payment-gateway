<?php

return [
    'enabled' => (bool) env('WEBHOOKS_ENABLED', true),

    'timeout_sec' => (int) env('WEBHOOKS_TIMEOUT_SEC', 10),

    'allow_private_urls' => (bool) env('WEBHOOKS_ALLOW_PRIVATE_URLS', false),

    'pending_recovery_limit' => (int) env('WEBHOOKS_PENDING_RECOVERY_LIMIT', 100),

    'delivering_stale_seconds' => (int) env('WEBHOOKS_DELIVERING_STALE_SECONDS', 120),

    'retries' => [
        'max_attempts' => (int) env('WEBHOOKS_MAX_ATTEMPTS', 3),
        'backoff_sec' => [
            60,
            300,
            900,
            3600,
            10800,
        ],
    ],
];
