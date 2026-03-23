<?php

namespace App\Services\CoinBasedLogic;

/**
 * Dash JSON-RPC client.
 */
class DashRpc extends AbstractJsonRpcCoin
{
    public function __construct()
    {
        $cfg = config('dash');

        $uri = sprintf('http://%s:%d', $cfg['rpc_host'], $cfg['rpc_port']);

        parent::__construct($uri, [$cfg['rpc_user'], $cfg['rpc_password']], 5);
    }
}
