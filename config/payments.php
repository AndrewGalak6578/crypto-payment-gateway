<?php

return [
    'invoice_ttl_minutes' => env('INVOICE_TTL_MINUTES', 60),

    // for state machine in future
    'paid_slippage_usd' => env('PAID_SLIPPAGE_USD', 1.0),
    'fixate_slippage_usd' => env('FIXATE_SLIPPAGE_USD', 0.10),

    // later in MonitorInvoiceJob
    'monitor_ttl_hours' => env('PAY_MONITOR_TTL_HOURS', 24),
    'monitor_poll_fast_sec' => env('PAY_MONITOR_FAST_SEC', 60),
    'monitor_poll_slow_sec' => env('PAY_MONITOR_SLOW_SEC', 300),
    'monitor_fast_phase_minutes' => env('PAY_MONITOR_FAST_MIN', 30),
];
