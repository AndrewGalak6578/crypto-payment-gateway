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
         | Gas sponsor source by EVM network
         |------------------------------------------------------------------
         |
         | Dedicated key_ref used only for native gas top-ups before ERC-20
         | sweeps from deposit addresses.
         |
         */
        'gas_station_key_refs' => [
            'evm_local' => env('PAYMENT_EVM_LOCAL_GAS_STATION_KEY_REF', 'anvil:default'),
        ],

        /*
         |------------------------------------------------------------------
         | ERC-20 gas top-up controls
         |------------------------------------------------------------------
         |
         | target_min_native_*: minimum native balance to keep on source.
         | safety_buffer_*: extra wei above estimated transfer gas cost.
         | retry_delay_seconds: when payout retry job should be re-run.
         | pending_cooldown_seconds: dedupe window for already submitted top-up.
         |
         */
        'gas_topup' => [
            'enabled' => env('PAYMENT_EVM_GAS_TOPUP_ENABLED', true),
            'target_min_native_wei' => env('PAYMENT_EVM_GAS_TOPUP_TARGET_MIN_NATIVE_WEI'),
            'target_min_native_decimal' => env('PAYMENT_EVM_GAS_TOPUP_TARGET_MIN_NATIVE_DECIMAL', '0.0002'),
            'safety_buffer_wei' => env('PAYMENT_EVM_GAS_TOPUP_SAFETY_BUFFER_WEI'),
            'safety_buffer_decimal' => env('PAYMENT_EVM_GAS_TOPUP_SAFETY_BUFFER_DECIMAL', '0.00005'),
            'retry_delay_seconds' => (int) env('PAYMENT_EVM_GAS_TOPUP_RETRY_DELAY_SECONDS', 30),
            'pending_cooldown_seconds' => (int) env('PAYMENT_EVM_GAS_TOPUP_PENDING_COOLDOWN_SECONDS', 45),
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
