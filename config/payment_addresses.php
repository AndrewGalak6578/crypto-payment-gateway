<?php

return [
    'evm' => [
        'derivation_path_template' => env(
            'PAYMENT_EVM_DERIVATION_PATH_TEMPLATE',
            "m/44'/60'/0'/0/%d"
        ),

        /*
         |------------------------------------------------------------------
         | Primary EVM deriver implementation
         |------------------------------------------------------------------
         |
         | Set to a class name that implements EvmAddressDeriverInterface
         | to use real custody/HD derivation in runtime.
         |
         */
        'deriver' => env('PAYMENT_EVM_DERIVER'),

        /*
         |------------------------------------------------------------------
         | Product anchor point custody/HD key source
         |------------------------------------------------------------------
         |
         | key_ref is not necessarily a private key.
         | This is the link to the key material source:
         | - vault key
         | - custody wallet
         | - xpub-like source
         | - external signer/derivation provider
         |
         */
        'default_key_refs' => [
            'evm_local' => env('PAYMENT_EVM_LOCAL_KEY_REF', 'anvil:default'),
        ],

        'network_key_refs' => [
            // 'evm_local' => [
            //     'eth_local' => 'vault:hot-wallet-eth-local',
            // ],
        ],

        'merchant_key_refs' => [
            // 1 => [
            //     'evm_local' => [
            //         'eth_local' => 'vault:merchant-1-eth-local',
            //     ],
            // ],
        ],

        /*
         |------------------------------------------------------------------
         | Temporary dev-only mode
         |------------------------------------------------------------------
         |
         | In local/testing you can use DevRpcAccountAddressDeriver,
         | which is a finite pool backed by eth_accounts.
         | When accounts are exhausted allocation fails with runtime error.
         |
         */
        'allow_dev_rpc_accounts' => env('PAYMENT_EVM_ALLOW_DEV_RPC_ACCOUNTS', true),
    ],
];
