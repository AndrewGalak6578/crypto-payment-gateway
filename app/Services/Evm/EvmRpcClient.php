<?php
declare(strict_types=1);

namespace App\Services\Evm;

use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use GuzzleHttp\Client;
use Throwable;
use function Symfony\Component\Translation\t;

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

    public function getBlockByNumber(string $block = 'latest', bool $fullTransactions = false): ?array
    {
        $result = $this->call('eth_getBlockByNumber', [$block, $fullTransactions]);

        return is_array($result) ? $result : null;
    }

    public function getTransactionByHash(string $txHash): ?array
    {
        $result = $this->call('eth_getTransactionByHash', [$txHash]);

        return is_array($result) ? $result : null;
    }

    public function getTransactionReceiptByHash(string $txHash): ?array
    {
        return $this->getTransactionReceipt($txHash);
    }

    public function getBlockTransactionCountByNumber(string $block = 'latest'): int
    {
        $result = $this->call('eth_getBlockTransactionCountByNumber', [$block]);

        return $this->hexToInt((string) $result);
    }

    public function getTransactionByBlockNumberAndIndex(string $blockNumberHex, string $txIndexHex): ?array
    {
        $result = $this->call('eth_getTransactionByBlockNumberAndIndex', [$blockNumberHex, $txIndexHex]);

        return is_array($result) ? $result : null;
    }

    /**
     * Returns current gas price
     */
    public function gasPriceWei(): string
    {
        return $this->hexToDecimalString((string) $this->call('eth_gasPrice'));
    }

    public function getTransactionCount(string $address, string $block = 'pending'): int
    {
        $result = $this->call('eth_getTransactionCount', [$address, $block]);

        return $this->hexToInt((string) $result);
    }

    public function estimateGas(array $transaction): string
    {
        $result = $this->call('eth_estimateGas', [$transaction]);

        return $this->hexToDecimalString((string) $result);
    }

    public function sendRawTransaction(string $rawTransaction): string
    {
        return (string) $this->call('eth_sendRawTransaction', [$rawTransaction]);
    }

    private function hexToInt(string $value): int
    {
        $value = strtolower(trim($value));

        if ($value === '' || $value === '0x') {
            return 0;
        }

        return intval($value, 16);
    }

    public function toHexQuantity(int $value): string
    {
        if ($value < 0) {
            return '0x0';
        }

        return '0x' . dechex($value);
    }

    public function hexToNullableInt(?string $value): ?int
    {
        if ($value === null) {
            return null;
        }

        $value = trim($value);

        if ($value === '' || strtolower($value) === '0x') {
            return null;
        }

        return $this->hexToInt($value);
    }

    public function weiToDecimalString(string $wei, int $decimals = 18): string
    {
        $wei = ltrim(trim($wei), '0');

        if ($wei === '') {
            return '0';
        }

        if ($decimals <= 0) {
            return $wei;
        }

        if (strlen($wei) <= $decimals) {
            $wei = str_pad($wei, $decimals + 1, '0', STR_PAD_LEFT);
        }

        $integer = substr($wei, 0, -$decimals);
        $fraction = substr($wei, -$decimals);

        $integer = ltrim($integer, '0');
        $integer = $integer === '' ? '0' : $integer;
        $fraction = rtrim($fraction, '0');

        if ($fraction === '') {
            return $integer;
        }

        return $integer . '.' . $fraction;
    }

    public function hexToDecimalValueString(string $hex, int $decimals = 18): string
    {
        $atomic = $this->hexToDecimalString($hex);

        return $this->weiToDecimalString($atomic, $decimals);
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

    public function decimalToHexQuantity(string $value): string
    {
        $value = trim($value);

        if ($value === '' || $value === '0') {
            return '0x0';
        }

        $value = ltrim($value, '0');
        if ($value === '') {
            return '0x0';
        }

        $hex = '';

        while ($value !== '0') {
            [$value, $remainder] = $this->decimalDivmod($value, 16);
            $hex = dechex($remainder) . $hex;
        }

        return '0x' . $hex;
    }

    public function decimalStringToAtomic(string $amountDecimal, int $decimals = 18): string
    {
        $amountDecimal = trim($amountDecimal);

        if ($amountDecimal === '' || $amountDecimal === '0') {
            return '0';
        }

        if (!str_contains($amountDecimal, '.')) {
            return $amountDecimal . str_repeat('0', $decimals);
        }

        [$integer, $fraction] = explode('.', $amountDecimal, 2);

        $integer = preg_replace('/\D/', '', $integer ?? '') ?: '0';
        $fraction = preg_replace('/\D/', '', $fraction ?? '') ?: '';

        if (strlen($fraction) > $decimals) {
            $fraction = substr($fraction, 0, $decimals);
        }

        $fraction = str_pad($fraction, $decimals, '0', STR_PAD_RIGHT);

        $result = ltrim($integer . $fraction, '0');

        return  $result === '' ? '0' : $result;
    }

    private function decimalDivmod(string $number, int $divisor): array
    {
        $number = ltrim($number, '0');
        $number = $number === '' ? '0' : $number;

        $quotient = '';
        $remainder = 0;

        foreach (str_split($number) as $digit) {
            $current = ($remainder * 10) + (int) $digit;
            $quotientDigit = intdiv($current, $divisor);
            $remainder = $current % $divisor;

            if ($quotient !== '' || $quotientDigit > 0) {
                $quotient .= (string) $quotientDigit;
            }
        }

        return [$quotient === '' ? '0' : $quotient, $remainder];
    }

    public function addDecimalStrings(string $left, string $right): string
    {
        $left = ltrim($left, '0');
        $right = ltrim($right, '0');

        $left = $right === '' ? '0' : $left;
        $right = $right === '' ? '0' : $right;

        $carry = 0;
        $result = '';

        $i = strlen($left) - 1;
        $j = strlen($right) - 1;

        while ($i >= 0 || $j >= 0 || $carry > 0) {
            $a = $i >= 0 ? (int) $left[$i] : 0;
            $b = $j >= 0 ? (int) $right[$j] : 0;
            $sum = $a + $b + $carry;

            $result = ($sum % 10) . $result;
            $carry = intdiv($sum, 10);

            $i--;
            $j--;
        }

        return ltrim($result, '0') ?: '0';
    }

    public function subtractDecimalStrings(string $left, string $right): string
    {
        $left = ltrim($left, '0');
        $right = ltrim($right, '0');

        $left = $left === '' ? '0' : $left;
        $right = $right === '' ? '0' : $right;

        if ($this->compareDecimalStrings($left, $right) < 0) {
            return '0';
        }

        $borrow = 0;
        $result = '';

        $i = strlen($left) - 1;
        $j = strlen($right) - 1;

        while ($i >= 0) {
            $a = (int) $left[$i] - $borrow;
            $b = $j >= 0 ? (int) $right[$j] : 0;

            if ($a < $b) {
                $a += 10;
                $borrow = 1;
            } else {
                $borrow = 0;
            }

            $result = (string) ($a - $b) . $result;

            $i--;
            $j--;
        }

        return ltrim($result, '0') ?: '0';
    }

    public function multiplyDecimalStrings(string $left, string $right): string
    {
        $left = ltrim($left, '0');
        $right = ltrim($right, '0');

        $left = $left === '' ? '0' : $left;
        $right = $right === '' ? '0' : $right;

        if ($left === '0' || $right === '0') {
            return '0';
        }

        $leftLen = strlen($left);
        $rightLen = strlen($right);
        $result = array_fill(0, $leftLen + $rightLen, 0);

        for ($i = $leftLen - 1; $i >= 0; $i--) {
            for ($j = $rightLen - 1; $j >= 0; $j--) {
                $mul = ((int) $left[$i]) * ((int) $right[$j]);
                $sum = $mul + $result[$i + $j + 1];

                $result[$i + $j + 1] = $sum % 10;
                $result[$i + $j] += intdiv($sum, 10);
            }
        }

        $out = implode('', $result);

        return ltrim($out, '0') ?: '0';
    }

    public function compareDecimalStrings(string $left, string $right): int
    {
        $left = ltrim($left, '0');
        $right = ltrim($right, '0');

        $left = $left === '' ? '0' : $left;
        $right = $right === '' ? '0' : $right;

        $leftLen = strlen($left);
        $rightLen = strlen($right);

        if ($leftLen < $rightLen) {
            return -1;
        }

        if ($leftLen > $rightLen) {
            return 1;
        }

        return $left <=> $right;
    }
}
