<?php

return [
    'merchant.owner' => [
        'portal.view',
        'invoices.read',
        'balances.read',
        'wallets.read',
        'wallets.write',
        'webhooks.read',
        'webhooks.write',
        'api_keys.read',
        'api_keys.write',
        'merchant_users.read',
        'merchant_users.write',
    ],

    'merchant.admin' => [
        'portal.view',
        'invoices.read',
        'balances.read',
        'wallets.read',
        'wallets.write',
        'webhooks.read',
        'webhooks.write',
        'api_keys.read',
        // 'api_keys.write' пока не даём
        'merchant_users.read',
    ],

    'merchant.analyst' => [
        'portal.view',
        'invoices.read',
        'balances.read',
        'wallets.read',
        'webhooks.read',
    ],

    'merchant.viewer' => [
        'portal.view',
        'invoices.read',
        'balances.read',
    ],
];
