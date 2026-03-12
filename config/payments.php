<?php

return [
    // invoice lifetime
    'invoice' => [
        'ttl_minutes' => (int) env('INVOICE_TTL_MINUTES', 60),
    ],

    // confirmations required to treat payment as confirmed
    'confirmations' => (int) env('INVOICE_PAYMENT_CONFIRMATIONS', 1),

    // slippage / tolerances
    'slippage' => [
        // for "paid" in coin units: allow % underpayment
        'paid_coin_percent' => (float) env('PAID_COIN_PERCENT', 0.5),

        // for "fixated" in usd: allow absolute diff (might be needed later)
        'fixate_usd' => (float) env('FIXATE_SLIPPAGE_USD', 0.10),

        // for "paid" in usd: allow absolute diff (might be needed later)
        'paid_usd' => (float) env('PAID_SLIPPAGE_USD', 1.0),
    ],

    // monitoring / polling
    'monitor' => [
        'enabled' => (bool) env('PAY_MONITOR_ENABLED', true),
        'ttl_hours' => (int) env('PAY_MONITOR_TTL_HOURS', 24),
        'poll_fast_sec' => (int) env('PAY_MONITOR_FAST_SEC', 60),
        'poll_slow_sec' => (int) env('PAY_MONITOR_SLOW_SEC', 300),
        'fast_phase_minutes' => (int) env('PAY_MONITOR_FAST_MIN', 30),
    ],
];
