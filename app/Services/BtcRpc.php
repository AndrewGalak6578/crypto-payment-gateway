<?php

namespace App\Services;

/**
 * Bitcoin JSON-RPC client.
 */
class BtcRpc extends AbstractJsonRpcCoin
{
    public function __construct()
    {
        $cfg = config('btc');

        $uri = sprintf('http://%s:%d', $cfg['rpc_host'], $cfg['rpc_port']);

        parent::__construct($uri, [$cfg['rpc_user'], $cfg['rpc_password']], 5);
    }
}
