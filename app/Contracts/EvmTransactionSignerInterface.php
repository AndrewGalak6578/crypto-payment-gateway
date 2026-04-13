<?php
declare(strict_types=1);

namespace App\Contracts;

use App\Data\EvmSweepSource;

interface EvmTransactionSignerInterface
{
    /**
     * @param array<string, mixed> $transaction
     * @return array{raw_tx: string, tx_hash?: string|null, meta?: array<string, mixed>}
     */
    public function signTransaction(
        string $networkKey,
        EvmSweepSource $source,
        array $transaction
    ): array;
}
