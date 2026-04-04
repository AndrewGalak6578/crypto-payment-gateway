<?php

return [
    'btc' => [
        'network' => 'bitcoin',
        'type' => 'native',
        'symbol' => 'BTC',
        'display_name' => 'Bitcoin',
        'decimals' => 8,
        'settlement_scale' => 8,
        'epsilon' => 0.00000001,
        'enabled' => true,
        'legacy_coin' => 'btc',
        'rate' => [
            'coingecko_id' => 'bitcoin',
            'coinbase_pair' => 'BTC-USD',
            'sanity_min' => 1000,
            'sanity_max' => 500000,
        ],
    ],

    'ltc' => [
        'network' => 'litecoin',
        'type' => 'native',
        'symbol' => 'LTC',
        'display_name' => 'Litecoin',
        'decimals' => 8,
        'settlement_scale' => 8,
        'epsilon' => 0.00000001,
        'enabled' => true,
        'legacy_coin' => 'ltc',
        'rate' => [
            'coingecko_id' => 'litecoin',
            'coinbase_pair' => 'LTC-USD',
            'sanity_min' => 1,
            'sanity_max' => 5000,
        ],
    ],

    'dash' => [
        'network' => 'dash',
        'type' => 'native',
        'symbol' => 'DASH',
        'display_name' => 'Dash',
        'decimals' => 8,
        'settlement_scale' => 3,
        'epsilon' => 0.001,
        'enabled' => true,
        'legacy_coin' => 'dash',
        'rate' => [
            'coingecko_id' => 'dash',
            'coinbase_pair' => 'DASH-USD',
            'sanity_min' => 1,
            'sanity_max' => 5000,
        ],
    ],

    // Пока не включаем в продукт, но уже готовим форму
    'eth_local' => [
        'network' => 'evm_local',
        'type' => 'native',
        'symbol' => 'ETH',
        'display_name' => 'Ether',
        'decimals' => 18,
        'settlement_scale' => 18,
        'epsilon' => 0.000000000000000001,
        'enabled' => true,
        'legacy_coin' => null,
        'rate' => [
            'coingecko_id' => 'ethereum',
            'coinbase_pair' => 'ETH-USD',
            'sanity_min' => 100,
            'sanity_max' => 50000,
        ],
    ],
];
