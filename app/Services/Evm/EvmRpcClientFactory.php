<?php

declare(strict_types=1);

namespace App\Services\Evm;

class EvmRpcClientFactory
{
    public function make(string $rpcUrl): EvmRpcClient
    {
        return new EvmRpcClient($rpcUrl);
    }
}
