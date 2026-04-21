<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Data\EvmGasTopUpOutcome;
use App\Data\EvmSweepSource;
use App\Models\Invoice;
use App\Models\SuperWallet;

interface EvmGasTopUpServiceInterface
{
    public function ensureTopUpForErc20Transfer(
        Invoice $invoice,
        EvmSweepSource $source,
        SuperWallet $destination,
        string $amountDecimal,
    ): EvmGasTopUpOutcome;
}
