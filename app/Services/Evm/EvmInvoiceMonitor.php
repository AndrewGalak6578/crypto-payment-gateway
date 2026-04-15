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
    private const ERC20_TRANSFER_TOPIC = '0xddf252ad1be2c89b69c2b068fc378daa952ba7f163c4a11628f55a4df523b3ef';

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
        $asset = $this->assets->get($assetKey);
        $decimals = (int) ($asset['decimals'] ?? 18);

        $address = strtolower(trim((string) $invoice->pay_address));
        if ($address === '') {
            throw new RuntimeException("Invoice [{$invoice->id}] has empty pay_address.");
        }

        $currentBlock = $client->blockNumber();
        $monitorFromBlock = $this->resolveMonitorFromBlock($invoice, $currentBlock);

        $assetType = strtolower((string) ($asset['type'] ?? 'native'));

        if ($assetType === 'native') {
            return $this->detectNative(
                client: $client,
                address: $address,
                decimals: $decimals,
                monitorFromBlock: $monitorFromBlock,
                currentBlock: $currentBlock,
                requiredConfirmations: $requiredConfirmations,
            );
        }

        $tokenStandard = strtolower((string) ($asset['token_standard'] ?? ''));
        if ($assetType === 'token' && $tokenStandard === 'erc20') {
            return $this->detectErc20(
                client: $client,
                asset: $asset,
                address: $address,
                decimals: $decimals,
                monitorFromBlock: $monitorFromBlock,
                currentBlock: $currentBlock,
                requiredConfirmations: $requiredConfirmations,
            );
        }

        throw new RuntimeException(
            "Unsupported EVM asset type [{$assetType}] with token standard [{$tokenStandard}] for asset [{$assetKey}]."
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

    private function detectNative(
        EvmRpcClient $client,
        string $address,
        int $decimals,
        int $monitorFromBlock,
        int $currentBlock,
        int $requiredConfirmations,
    ): EvmPaymentDetectionResult {
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

        return $this->buildResult(
            client: $client,
            decimals: $decimals,
            receivedAllAtomic: $receivedAllAtomic,
            receivedConfirmedAtomic: $receivedConfirmedAtomic,
            firstTxHash: $firstTxHash,
            firstAmountAtomic: $firstAmountAtomic,
            firstSeenAt: $firstSeenAt,
            firstConfirmedBlock: $firstConfirmedBlock,
            currentBlock: $currentBlock,
            requiredConfirmations: $requiredConfirmations,
            transactions: $transactions,
        );
    }

    /**
     * @param array<string, mixed> $asset
     */
    private function detectErc20(
        EvmRpcClient $client,
        array $asset,
        string $address,
        int $decimals,
        int $monitorFromBlock,
        int $currentBlock,
        int $requiredConfirmations,
    ): EvmPaymentDetectionResult {
        $contractAddress = strtolower((string) ($asset['contract_address'] ?? ''));
        if (!$this->isValidEvmAddress($contractAddress)) {
            throw new RuntimeException("Invalid ERC-20 contract address [{$contractAddress}].");
        }

        $recipientTopic = $this->addressToTopic($address);
        $logs = $client->getLogs([
            'fromBlock' => $client->toHexQuantity($monitorFromBlock),
            'toBlock' => $client->toHexQuantity($currentBlock),
            'address' => $contractAddress,
            'topics' => [
                self::ERC20_TRANSFER_TOPIC,
                null,
                $recipientTopic,
            ],
        ]);

        $txAggregates = [];
        $firstEvent = null;

        foreach ($logs as $log) {
            if ($this->isRemovedLog($log['removed'] ?? false)) {
                continue;
            }

            $topics = $log['topics'] ?? null;
            if (!is_array($topics)) {
                continue;
            }

            $topic0 = strtolower((string) ($topics[0] ?? ''));
            if ($topic0 !== self::ERC20_TRANSFER_TOPIC) {
                continue;
            }

            $logRecipient = $this->topicToAddress((string) ($topics[2] ?? ''));
            if ($logRecipient === null || $logRecipient !== $address) {
                continue;
            }

            $txHash = (string) ($log['transactionHash'] ?? '');
            if ($txHash === '') {
                continue;
            }

            $txBlock = $client->hexToNullableInt(
                is_string($log['blockNumber'] ?? null) ? $log['blockNumber'] : null
            );
            if ($txBlock === null) {
                continue;
            }

            $amountAtomic = $this->hexToAtomicDecimalString((string) ($log['data'] ?? '0x0'));
            if ($this->isZeroAtomic($amountAtomic)) {
                continue;
            }

            $txIndex = $client->hexToNullableInt(
                is_string($log['transactionIndex'] ?? null) ? $log['transactionIndex'] : null
            ) ?? PHP_INT_MAX;

            $logIndex = $client->hexToNullableInt(
                is_string($log['logIndex'] ?? null) ? $log['logIndex'] : null
            ) ?? PHP_INT_MAX;

            if (
                $firstEvent === null
                || $this->isEarlierChainPosition(
                    candidateBlockNumber: $txBlock,
                    candidateTransactionIndex: $txIndex,
                    candidateLogIndex: $logIndex,
                    currentBlockNumber: (int) $firstEvent['block_number'],
                    currentTransactionIndex: (int) $firstEvent['transaction_index'],
                    currentLogIndex: (int) $firstEvent['log_index'],
                )
            ) {
                $firstEvent = [
                    'txid' => $txHash,
                    'amount_atomic' => $amountAtomic,
                    'block_number' => $txBlock,
                    'transaction_index' => $txIndex,
                    'log_index' => $logIndex,
                ];
            }

            if (!array_key_exists($txHash, $txAggregates)) {
                $txAggregates[$txHash] = [
                    'txid' => $txHash,
                    'amount_atomic' => $amountAtomic,
                    'block_number' => $txBlock,
                ];
                continue;
            }

            $txAggregates[$txHash]['amount_atomic'] = $this->addDecimalStrings(
                (string) $txAggregates[$txHash]['amount_atomic'],
                $amountAtomic
            );

            if ((int) $txAggregates[$txHash]['block_number'] > $txBlock) {
                $txAggregates[$txHash]['block_number'] = $txBlock;
            }
        }

        $blockTimeCache = [];
        $transactions = [];
        $receivedAllAtomic = '0';
        $receivedConfirmedAtomic = '0';

        foreach ($txAggregates as $txData) {
            $txHash = (string) $txData['txid'];
            $amountAtomic = (string) $txData['amount_atomic'];
            $txBlock = (int) $txData['block_number'];
            $blockTime = $this->resolveBlockTimestamp($client, $txBlock, $blockTimeCache);
            $confirmations = max(0, $currentBlock - $txBlock + 1);

            $transactions[] = [
                'txid' => $txHash,
                'amount' => (float) $client->weiToDecimalString($amountAtomic, $decimals),
                'time' => $blockTime,
                'block_number' => $txBlock,
                'confirmations' => $confirmations,
            ];

            $receivedAllAtomic = $this->addDecimalStrings($receivedAllAtomic, $amountAtomic);

            if ($confirmations >= $requiredConfirmations) {
                $receivedConfirmedAtomic = $this->addDecimalStrings($receivedConfirmedAtomic, $amountAtomic);
            }
        }

        $firstTxHash = is_array($firstEvent) ? (string) $firstEvent['txid'] : null;
        $firstAmountAtomic = is_array($firstEvent) ? (string) $firstEvent['amount_atomic'] : null;
        $firstSeenAt = is_array($firstEvent)
            ? $this->resolveBlockTimestamp($client, (int) $firstEvent['block_number'], $blockTimeCache)
            : null;
        $firstConfirmedBlock = null;

        if (is_array($firstEvent)) {
            $firstBlock = (int) $firstEvent['block_number'];
            $firstConfirmations = max(0, $currentBlock - $firstBlock + 1);
            $firstConfirmedBlock = $firstConfirmations >= $requiredConfirmations ? $firstBlock : null;
        }

        return $this->buildResult(
            client: $client,
            decimals: $decimals,
            receivedAllAtomic: $receivedAllAtomic,
            receivedConfirmedAtomic: $receivedConfirmedAtomic,
            firstTxHash: $firstTxHash,
            firstAmountAtomic: $firstAmountAtomic,
            firstSeenAt: $firstSeenAt,
            firstConfirmedBlock: $firstConfirmedBlock,
            currentBlock: $currentBlock,
            requiredConfirmations: $requiredConfirmations,
            transactions: $transactions,
        );
    }

    private function isEarlierChainPosition(
        int $candidateBlockNumber,
        int $candidateTransactionIndex,
        int $candidateLogIndex,
        int $currentBlockNumber,
        int $currentTransactionIndex,
        int $currentLogIndex,
    ): bool {
        if ($candidateBlockNumber !== $currentBlockNumber) {
            return $candidateBlockNumber < $currentBlockNumber;
        }

        if ($candidateTransactionIndex !== $currentTransactionIndex) {
            return $candidateTransactionIndex < $currentTransactionIndex;
        }

        return $candidateLogIndex < $currentLogIndex;
    }

    /**
     * @param array<int, int> $cache
     */
    private function resolveBlockTimestamp(EvmRpcClient $client, int $blockNumber, array &$cache): int
    {
        if (array_key_exists($blockNumber, $cache)) {
            return $cache[$blockNumber];
        }

        $block = $client->getBlockByNumber($client->toHexQuantity($blockNumber), false);
        if (!is_array($block)) {
            $cache[$blockNumber] = 0;
            return 0;
        }

        $timestampHex = (string) ($block['timestamp'] ?? '0x0');
        $cache[$blockNumber] = $client->hexToNullableInt($timestampHex) ?? 0;

        return $cache[$blockNumber];
    }

    private function isRemovedLog(mixed $removed): bool
    {
        if (is_bool($removed)) {
            return $removed;
        }

        if (is_string($removed)) {
            return strtolower($removed) === 'true';
        }

        return false;
    }

    private function addressToTopic(string $address): string
    {
        $address = strtolower(trim($address));
        if (!$this->isValidEvmAddress($address)) {
            throw new RuntimeException("Invalid EVM recipient address [{$address}].");
        }

        return '0x000000000000000000000000' . substr($address, 2);
    }

    private function topicToAddress(string $topic): ?string
    {
        $topic = strtolower(trim($topic));

        if (!str_starts_with($topic, '0x')) {
            return null;
        }

        $hex = substr($topic, 2);
        if (strlen($hex) !== 64 || !ctype_xdigit($hex)) {
            return null;
        }

        return '0x' . substr($hex, 24);
    }

    private function isValidEvmAddress(string $address): bool
    {
        return (bool) preg_match('/^0x[a-f0-9]{40}$/', strtolower($address));
    }

    private function buildResult(
        EvmRpcClient $client,
        int $decimals,
        string $receivedAllAtomic,
        string $receivedConfirmedAtomic,
        ?string $firstTxHash,
        ?string $firstAmountAtomic,
        ?int $firstSeenAt,
        ?int $firstConfirmedBlock,
        int $currentBlock,
        int $requiredConfirmations,
        array $transactions,
    ): EvmPaymentDetectionResult {
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
