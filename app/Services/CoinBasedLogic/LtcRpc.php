<?php

namespace App\Services\CoinBasedLogic;

/**
 * Litecoin JSON-RPC client.
 */
class LtcRpc extends AbstractJsonRpcCoin
{
    public function __construct()
    {
        $cfg = config('ltc');

        $uri = sprintf('http://%s:%d', $cfg['rpc_host'], $cfg['rpc_port']);

        parent::__construct($uri, [$cfg['rpc_user'], $cfg['rpc_password']], 5);
    }
}
