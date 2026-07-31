<?php

declare(strict_types=1);

return [
    'accounting_enabled' => (bool) env('CUSTODY_ACCOUNTING_ENABLED', false),
    'journal_writes_enabled' => (bool) env('CUSTODY_JOURNAL_WRITES_ENABLED', false),
    'invoice_routing_enabled' => (bool) env('CUSTODY_INVOICE_ROUTING_ENABLED', false),
    'payout_requests_enabled' => (bool) env('PAYOUT_REQUESTS_ENABLED', false),
    'payout_automatic_requests_enabled' => (bool) env('PAYOUT_AUTOMATIC_REQUESTS_ENABLED', false),
    'payout_execution_enabled' => (bool) env('PAYOUT_EXECUTION_ENABLED', false),
];
