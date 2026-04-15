<?php
declare(strict_types=1);

namespace App\Services\Evm;

use App\Data\EvmGasCheckResult;
use RuntimeException;

final class EvmTokenGasChecker
{
    public function __construct(
        private readonly EvmRpcClient $client
    )
    {
    }

    public function checkForTransaction(string $fromAddress, string $toAddress, string $data): EvmGasCheckResult
    {
        $fromAddress = strtolower(trim($fromAddress));
        $toAddress = strtolower(trim($toAddress));

        if ($fromAddress === '' || $toAddress === '') {
            throw new RuntimeException('Gas checker requires non-empty from/to address');
        }

        $gasPriceWei = $this->client->gasPriceWei();
        $nativeBalanceWei = $this->client->getBalanceWei($fromAddress, 'latest');

        $estimatePayload = [
            'from' => $fromAddress,
            'to' => $toAddress,
            'value' => '0x0',
            'data' => $data
        ];

        $gasLimit = $this->client->estimateGas($estimatePayload);
        $estimatedCostWei = $this->client->multiplyDecimalStrings($gasLimit, $gasPriceWei);

        $hasEnoughGas = $this->client->compareDecimalStrings($nativeBalanceWei, $estimatedCostWei) >= 0;

        return new EvmGasCheckResult(
            hasEnoughGas: $hasEnoughGas,
            gasLimit: $gasLimit,
            gasPriceWei: $gasPriceWei,
            estimatedCostWei: $estimatedCostWei,
            nativeBalanceWei: $nativeBalanceWei,
            reason: $hasEnoughGas ? null : 'insufficient_native_gas',
            meta: [
                'from' => $fromAddress,
                'to' => $toAddress,
            ]
        );
    }
}
