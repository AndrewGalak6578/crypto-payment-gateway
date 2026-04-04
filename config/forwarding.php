<?php
return [
    'enabled' => (bool) env('FORWARDING_ENABLED', false),

    'assets' => [
        'btc' => [
            'min' => (float) env('BTC_FORWARD_MIN_COIN', 0.00001000),
        ],
        'ltc' => [
            'min' => (float) env('LTC_FORWARD_MIN_COIN', 0.00100000),
        ],
        'dash' => [
            'min' => (float) env('DASH_FORWARD_MIN_COIN', 0.00100000),
        ],
    ],
];
