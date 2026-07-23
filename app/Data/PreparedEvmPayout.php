<?php

declare(strict_types=1);

namespace App\Data;

final readonly class PreparedEvmPayout
{
    /**
     * @param  array<string, mixed>  $transaction
     * @param  array<string, mixed>  $meta
     */
    public function __construct(
        public string $networkKey,
        public string $assetKey,
        public EvmSweepSource $source,
        public string $destinationAddress,
        public string $amountDecimal,
        public string $amountAtomic,
        public int $nonce,
        public int $chainId,
        public int $broadcastBlockNumber,
        public array $transaction,
        public ?string $gasPriceWei = null,
        public ?string $gasLimit = null,
        public ?string $maxFeePerGasWei = null,
        public ?string $maxPriorityFeePerGasWei = null,
        public array $meta = [],
    ) {}

    public function fingerprint(): string
    {
        return hash('sha256', json_encode([
            'network_key' => $this->networkKey,
            'asset_key' => $this->assetKey,
            'chain_id' => $this->chainId,
            'source_address' => strtolower($this->source->address),
            'destination_address' => strtolower($this->destinationAddress),
            'amount_atomic' => $this->amountAtomic,
            'nonce' => $this->nonce,
            'transaction' => $this->transaction,
        ], JSON_THROW_ON_ERROR));
    }
}
