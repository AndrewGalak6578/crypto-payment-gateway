<?php

declare(strict_types=1);

namespace App\Services\Evm;

use App\Contracts\EvmInvoiceMonitorInterface;
use App\Data\EvmPaymentDetectionResult;
use App\Models\Invoice;
use App\Support\Assets\AssetRegistry;
use App\Support\Chains\ChainRegistry;
use RuntimeException;

final class EvmInvoiceMonitor implements EvmInvoiceMonitorInterface
{
    public function __construct(
        private readonly ChainRegistry $chains,
        private readonly AssetRegistry $assets,
    ) {
    }

    public function detect(Invoice $invoice, int $requiredConfirmations): EvmPaymentDetectionResult
    {
        $networkKey = $invoice->resolvedNetworkKey();
        $assetKey = $invoice->resolvedAssetKey();

        if ($this->chains->family($networkKey) !== 'evm') {
            throw new RuntimeException("Network [{$networkKey}] is not EVM.");
        }

        $chain = $this->chains->get($networkKey);
        $rpcUrl = (string) ($chain['rpc_url'] ?? '');

        if ($rpcUrl === '') {
            throw new RuntimeException("Missing rpc_url for EVM network [{$networkKey}]");
        }

        $client = new EvmRpcClient($rpcUrl);
        $decimals = (int) ($this->assets->get($assetKey)['decimals'] ?? 18);

        $address = strtolower((string) $invoice->pay_address);
        if ($address === '') {
            throw new RuntimeException("Invoice [{$invoice->id}] has empty pay_address.");
        }

        $currentBlock = $client->blockNumber();
        $monitorFromBlock = $this->resolveMonitorFromBlock($invoice, $currentBlock);

        $transactions = [];
        $receivedAllAtomic = '0';
        $receivedConfirmedAtomic = '0';

        $firstTxHash = null;
        $firstAmountAtomic = null;
        $firstSeenAt = null;
        $firstConfirmedBlock = null;

        for ($block = $monitorFromBlock; $block <= $currentBlock; $block++) {
            $blockHex = $client->toHexQuantity($block);
            $blockPayload = $client->getBlockByNumber($blockHex, false);

            if (!is_array($blockPayload)) {
                continue;
            }

            $timestampHex = (string) ($blockPayload['timestamp'] ?? '0x0');
            $blockTime = $client->hexToNullableInt($timestampHex) ?? 0;

            $txHashes = $blockPayload['transactions'] ?? [];
            if (!is_array($txHashes) || $txHashes === []) {
                continue;
            }

            foreach ($txHashes as $txHashRaw) {
                $txHash = (string) $txHashRaw;
                if ($txHash === '') {
                    continue;
                }

                $tx = $client->getTransactionByHash($txHash);
                if (!is_array($tx)) {
                    continue;
                }

                $to = strtolower((string) ($tx['to'] ?? ''));
                if ($to === '' || $to !== $address) {
                    continue;
                }

                $valueHex = (string) ($tx['value'] ?? '0x0');
                $valueAtomic = $this->hexToAtomicDecimalString($valueHex);

                if ($this->isZeroAtomic($valueAtomic)) {
                    continue;
                }

                $receipt = $client->getTransactionReceiptByHash($txHash);
                if (!is_array($receipt)) {
                    continue;
                }

                $statusHex = strtolower((string) ($receipt['status'] ?? '0x0'));
                if ($statusHex !== '0x1') {
                    continue;
                }

                $txBlock = $client->hexToNullableInt($receipt['blockNumber'] ?? null);
                if ($txBlock === null) {
                    continue;
                }

                $confirmations = max(0, $currentBlock - $txBlock + 1);

                $transactions[] = [
                    'txid' => $txHash,
                    'amount' => (float) $client->weiToDecimalString($valueAtomic, $decimals),
                    'time' => $blockTime,
                    'block_number' => $txBlock,
                    'confirmations' => $confirmations,
                ];

                $receivedAllAtomic = $this->addDecimalStrings($receivedAllAtomic, $valueAtomic);

                if ($confirmations >= $requiredConfirmations) {
                    $receivedConfirmedAtomic = $this->addDecimalStrings($receivedConfirmedAtomic, $valueAtomic);
                }

                if ($firstSeenAt === null || $blockTime < $firstSeenAt) {
                    $firstSeenAt = $blockTime;
                    $firstTxHash = $txHash;
                    $firstAmountAtomic = $valueAtomic;
                    $firstConfirmedBlock = $confirmations >= $requiredConfirmations ? $txBlock : null;
                }
            }
        }

        return new EvmPaymentDetectionResult(
            receivedAllDecimal: $client->weiToDecimalString($receivedAllAtomic, $decimals),
            receivedConfirmedDecimal: $client->weiToDecimalString($receivedConfirmedAtomic, $decimals),
            firstTxHash: $firstTxHash,
            firstAmountDecimal: $firstAmountAtomic !== null
                ? $client->weiToDecimalString($firstAmountAtomic, $decimals)
                : null,
            firstSeenAt: $firstSeenAt,
            firstConfirmedBlock: $firstConfirmedBlock,
            currentBlock: $currentBlock,
            requiredConfirmations: $requiredConfirmations,
            transactions: $transactions,
        );
    }

    private function resolveMonitorFromBlock(Invoice $invoice, int $currentBlock): int
    {
        $metadata = is_array($invoice->metadata) ? $invoice->metadata : [];
        $fromMeta = $metadata['evm']['monitor_from_block'] ?? null;

        if (is_numeric($fromMeta)) {
            $fromMeta = (int) $fromMeta;

            if ($fromMeta >= 0 && $fromMeta <= $currentBlock) {
                return $fromMeta;
            }
        }

        return max(0, $currentBlock - 200);
    }

    private function hexToAtomicDecimalString(string $hex): string
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
            $decimal = $this->multiplyDecimalString($decimal, 16);
            $decimal = $this->addDecimalStrings($decimal, (string) hexdec($char));
        }

        return ltrim($decimal, '0') ?: '0';
    }

    private function isZeroAtomic(string $value): bool
    {
        return ltrim($value, '0') === '';
    }

    private function addDecimalStrings(string $left, string $right): string
    {
        $left = ltrim($left, '0');
        $right = ltrim($right, '0');

        $left = $left === '' ? '0' : $left;
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

    private function multiplyDecimalString(string $number, int $multiplier): string
    {
        $number = ltrim($number, '0');
        $number = $number === '' ? '0' : $number;

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
}
