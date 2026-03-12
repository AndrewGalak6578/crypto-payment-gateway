<?php
return [
    'enabled' => (bool) env('FORWARDING_ENABLED', false),

    'min_coin' => [
        'btc' => (float) env('BTC_FORWARD_MIN_COIN', 0.00001000),
        'ltc' => (float) env('LTC_FORWARD_MIN_COIN', 0.00100000),
        'dash' => (float) env('DASH_FORWARD_MIN_COIN', 0.00100000),
    ]
];
