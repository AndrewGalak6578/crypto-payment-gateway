<?php

declare(strict_types=1);

return [
    'accounting_enabled' => env('CUSTODY_ACCOUNTING_ENABLED', false),
    'journal_writes_enabled' => env('CUSTODY_JOURNAL_WRITES_ENABLED', false),
    'phase2a_shadow_internal_credits_enabled' => env(
        'CUSTODY_PHASE2A_SHADOW_INTERNAL_CREDITS_ENABLED',
        false,
    ),
    'invoice_routing_enabled' => env('CUSTODY_INVOICE_ROUTING_ENABLED', false),
    'payout_requests_enabled' => env('PAYOUT_REQUESTS_ENABLED', false),
    'payout_automatic_requests_enabled' => env('PAYOUT_AUTOMATIC_REQUESTS_ENABLED', false),
    'payout_execution_enabled' => env('PAYOUT_EXECUTION_ENABLED', false),
];
