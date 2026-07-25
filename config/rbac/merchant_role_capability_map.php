<?php

return [
    'merchant.owner' => [
        'portal.view',
        'invoices.read',
        'invoices.write',
        'balances.read',
        'settlements.read',
        'settlements.write',
        'wallets.read',
        'wallets.write',
        'webhooks.read',
        'webhooks.write',
        'api_keys.read',
        'api_keys.write',
        'merchant_users.read',
        'merchant_users.write',
        'audit.read',
    ],

    'merchant.admin' => [
        'portal.view',
        'invoices.read',
        'invoices.write',
        'balances.read',
        'settlements.read',
        'settlements.write',
        'wallets.read',
        'wallets.write',
        'webhooks.read',
        'webhooks.write',
        'api_keys.read',
        // 'api_keys.write' пока не даём
        'merchant_users.read',
        'audit.read',
    ],

    'merchant.analyst' => [
        'portal.view',
        'invoices.read',
        'balances.read',
        'settlements.read',
        'wallets.read',
        'webhooks.read',
    ],

    'merchant.viewer' => [
        'portal.view',
        'invoices.read',
        'balances.read',
        'settlements.read',
    ],
];
