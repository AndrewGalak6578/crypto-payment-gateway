<?php
declare(strict_types=1);

namespace App\Services\Evm;

use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use GuzzleHttp\Client;
use Throwable;

final class EvmRpcClient
{
    private Client $http;

    public function __construct(string $rpcUrl, int $timeout = 5)
    {
        $rpcUrl = trim($rpcUrl);

        if ($rpcUrl === '') {
            throw new RuntimeException('EVM RPC URL is empty.');
        }

        $this->http = new Client([
            'base_uri' => rtrim($rpcUrl, '/') . '/',
            'timeout' => $timeout
        ]);
    }

    /**
     * @throws Throwable
     * @throws GuzzleException
     */
    public function call(string $method, array $params = []): mixed
    {
        try {
            $response = $this->http->post('', [
                'json' => [
                    'jsonrpc' => '2.0',
                    'id' => 'laravel-evm',
                    'method' => $method,
                    'params' => $params,
                ]
            ]);
        } catch (Throwable $e) {
            Log::error('EVM RPC HTTP exception', [
                'method' => $method,
                'params' => $params,
                'error' => $e->getMessage()
            ]);

            throw $e;
        }

        $payload = json_decode((string)$response->getBody(), true);

        if (!is_array($payload)) {
            throw new RuntimeException('EVM RPC invalid JSON response');
        }

        if (!empty($payload['error'])) {
            throw new RuntimeException('EVM RPC error: ' . json_encode($payload['error']));
        }

        return $payload['result'] ?? null;
    }

    /**
     * @throws GuzzleException
     * @throws Throwable
     */
    public function clientVersion(): string
    {
        return (string) $this->call('web3_clientVersion');
    }

    public function chainId(): int
    {
        return (int) $this->hexToInt((string) $this->call('eth_chainId'));

    }

    public function blockNumber(): int
    {
        return (int) $this->hexToInt((string) $this->call('eth_blockNumber'));

    }

    public function getBalanceWei(string $address, string $block = 'latest'): string
    {
        $result = $this->call('eth_getBalance', [$address, $block]);

        return $this->hexToDecimalString($result);
    }

    public function getTransactionReceipt(string $txHash): ?array
    {
        $result = $this->call('eth_getTransactionReceipt', [$txHash]);

        return is_array($result) ? $result : null;
    }

    private function hexToInt(string $value): int
    {
        $value = strtolower(trim($value));

        if ($value === '' || $value === '0x') {
            return 0;
        }

        return intval($value, 16);
    }

    private function hexToDecimalString(string $hex): string
    {
        $hex = strtolower(trim($hex));

        if (str_starts_with($hex, '0x')) {
            $hex = substr($hex, 2);
        }

        if ($hex === '') {
            return '0';
        }

        $decimal = '0';

        foreach (str_split($hex) as $char) {
            $decimal = $this->decimalMultiply($decimal, 16);
            $decimal = $this->decimalAdd($decimal, hexdec($char));
        }

        return ltrim($decimal, '0') ?: '0';
    }

    private function decimalMultiply(string $number, int $multiplier): string
    {
        $carry = 0;
        $result = '';

        for ($i = strlen($number) - 1; $i >= 0; $i--) {
            $product = ((int) $number[$i] * $multiplier) + $carry;
            $result = ($product % 10) . $result;
            $carry = intdiv($product, 10);
        }

        while ($carry > 0) {
            $result = ($carry % 10) . $result;
            $carry = intdiv($carry, 10);
        }

        return ltrim($result, '0') ?: '0';
    }

    private function decimalAdd(string $number, int $addend): string
    {
        $carry = $addend;
        $result = '';

        for ($i = strlen($number) - 1; $i >= 0; $i--) {
            $sum = (int) $number[$i] + $carry;
            $result = ($sum % 10) . $result;
            $carry = intdiv($sum, 10);
        }

        while ($carry > 0) {
            $result = ($carry % 10) . $result;
            $carry = intdiv($carry, 10);
        }

        return ltrim($result, '0') ?: '0';
    }
}
