<?php
declare(strict_types=1);

namespace App\Contracts;

use App\Data\EvmPayoutResult;
use App\Data\EvmSweepSource;
use App\Models\Invoice;
use App\Models\PaymentAddress;
use App\Models\SuperWallet;

interface EvmPayoutSenderInterface
{
    public function sendNative(
        Invoice $invoice,
        EvmSweepSource $source,
        SuperWallet $destination,
        string $amountDecimal
    ): EvmPayoutResult;
}
