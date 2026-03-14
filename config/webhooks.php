<?php

return [
    'enabled' => (bool)env('WEBHOOKS_ENABLED', true),

    'timeout_sec' => (int)env('WEBHOOKS_TIMEOUT_SEC', 10),

    'retries' => [
        'max_attempts' => (int)env('WEBHOOKS_MAX_ATTEMPTS', 3),
        'backoff_sec' => [
            60,
            300,
            900,
            3600,
            10800,
        ]
    ],
];
