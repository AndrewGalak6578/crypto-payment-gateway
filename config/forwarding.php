<?php

$settlementReconciliationBackoff = array_values(array_filter(
    array_map(
        static fn (string $seconds): int => (int) trim($seconds),
        explode(',', (string) env('SETTLEMENT_RECONCILIATION_BACKOFF_SECONDS', '15,60,300,900,3600')),
    ),
    static fn (int $seconds): bool => $seconds > 0,
));

return [
    'enabled' => (bool) env('FORWARDING_ENABLED', false),
    'allow_platform_wallet_fallback' => (bool) env('FORWARDING_ALLOW_PLATFORM_WALLET_FALLBACK', false),

    'assets' => [
        'btc' => [
            'min' => (string) env('BTC_FORWARD_MIN_COIN', '0.00001000'),
        ],
        'ltc' => [
            'min' => (string) env('LTC_FORWARD_MIN_COIN', '0.00100000'),
        ],
        'dash' => [
            'min' => (string) env('DASH_FORWARD_MIN_COIN', '0.00100000'),
        ],
        'eth_usdt_local' => [
            'min' => (string) env('ETH_USDT_LOCAL_FORWARD_MIN_COIN', '100.000000'),
        ],
    ],

    'attempts' => [
        'reservation_lease_seconds' => (int) env('SETTLEMENT_RESERVATION_LEASE_SECONDS', 300),
        'reconciliation_lease_seconds' => (int) env('SETTLEMENT_RECONCILIATION_LEASE_SECONDS', 120),
        'reconciliation_backoff_seconds' => $settlementReconciliationBackoff !== []
            ? $settlementReconciliationBackoff
            : [15, 60, 300, 900, 3600],
        'evm_nonce_scan_blocks' => (int) env('SETTLEMENT_EVM_NONCE_SCAN_BLOCKS', 64),
    ],
];
