<?php
declare(strict_types=1);

namespace App\Contracts;

use App\Data\EvmPayoutResult;
use App\Data\EvmSweepSource;
use App\Models\Invoice;
use App\Models\SuperWallet;

interface EvmTokenPayoutSenderInterface
{
    public function sendToken(
        Invoice $invoice,
        EvmSweepSource $source,
        SuperWallet $destination,
        string $amountDecimal
    ): EvmPayoutResult;
}
