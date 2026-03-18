<?php

namespace App\Services;

use GuzzleHttp\Client;
use Illuminate\Support\Facades\Log;

/**
 * Base JSON-RPC adapter for UTXO nodes.
 */
class AbstractJsonRpcCoin implements CoinRpc
{
    protected Client $http;

    public function __construct(string $baseUri, ?array $auth = null, int $timeout = 5)
    {
        $options = [
            'base_uri' => rtrim($baseUri, '/') . '/',
            'timeout' => $timeout
        ];

        if ($auth) $options['auth'] = $auth;

        $this->http = new Client($options);
    }

    /**
     * Performs JSON-RPC request and returns "result" value.
     *
     * @param string $method JSON-RPC method.
     * @param array<int, mixed> $params JSON-RPC params list.
     * @return mixed
     */
    protected function call(string $method, array $params = [])
    {
        try {
            $resp = $this->http->post('', [
                'json' => [
                    'jsonrpc' => '1.0',
                    'id' => 'laravel',
                    'method' => $method,
                    'params' => $params,
                ],
            ]);
        } catch (\Throwable $e) {
            Log::error(static::class . ' HTTP RPC exception', [
                'method' => $method,
                'params' => $params,
                'error' => $e->getMessage()
            ]);

            throw $e;
        }

        $raw = (string)$resp->getBody();
        $body = json_decode($raw, true);

        if (!is_array($body)) {
            throw new \RuntimeException(static::class . ' RPC invalid JSON response');
        }

        if (!empty($body['error'])) {
            throw new \RuntimeException(static::class . ' RPC error: ' . json_encode($body['error']));
        }

        return $body['result'];
    }

    public function getNewAddress(string $label = ''): string
    {
        return (string)$this->call('getnewaddress', [$label]);
    }

    public function getReceivedTotals(string $address, int $confirmedMinConf): array
    {
        $all = (float)$this->call('getreceivedbyaddress', [$address, 0]);
        $conf = (float)$this->call('getreceivedbyaddress', [$address, $confirmedMinConf]);
        $unconf = max(0.0, $all - $conf);

        return ['confirmed' => $conf, 'unconfirmed' => $unconf, 'all' => $all];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getTransactionsByAddress(string $address, int $minConf = 1, int $count = 1000, ?string $label = null): array
    {
        $all = (array)$this->call('listtransactions', ['*', $count, 0, true]);

        return array_values(array_filter($all, function ($tx) use ($address, $minConf, $label) {
            if (($tx['category'] ?? '') !== 'receive') return false;
            if ((int)($tx['confirmations'] ?? -1) < $minConf) return false;

            $addrOk = isset($tx['address']) && $tx['address'] === $address;
            $labelOk = $label !== null && isset($tx['label']) && (string)$tx['label'] === (string)$label;

            return $addrOk || $labelOk;
        }));
    }

    public function sendToAddress(string $address, float $amount, ?float $feeRate = null): string
    {
        if ($feeRate !== null) {
            return (string)$this->call('sendtoaddress', [
                $address, $amount,
                null, null, false, null, null, null, null,
                $feeRate
            ]);
        }

        return (string)$this->call('sendtoaddress', [$address, $amount]);
    }

    public function getBalance(): float
    {
        return (float)$this->call('getbalance');
    }
}
