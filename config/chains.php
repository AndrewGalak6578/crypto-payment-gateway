<?php

return [
    'bitcoin' => [
        'family' => 'utxo',
        'driver' => 'btc',
        'display_name' => 'Bitcoin',
        'confirmations' => 1,
        'enabled' => true,
    ],

    'litecoin' => [
        'family' => 'utxo',
        'driver' => 'ltc',
        'display_name' => 'Litecoin',
        'confirmations' => 1,
        'enabled' => true,
    ],

    'dash' => [
        'family' => 'utxo',
        'driver' => 'dash',
        'display_name' => 'Dash',
        'confirmations' => 1,
        'enabled' => true,
    ],

    // NOT RUN YET
    'evm_local' => [
        'family' => 'evm',
        'driver' => 'evm',
        'display_name' => 'Local EVM',
        'chain_id' => 31337,
        'confirmations' => 1,
        'enabled' => false,
        'rpc_url' => env('EVM_LOCAL_RPC_URL'),
    ],
];
