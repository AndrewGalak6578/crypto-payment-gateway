<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Data\EvmPayoutResult;
use App\Data\EvmSweepSource;
use App\Data\PreparedEvmPayout;
use App\Models\Invoice;
use App\Models\SuperWallet;

interface EvmPayoutSenderInterface
{
    public function prepareNative(
        Invoice $invoice,
        EvmSweepSource $source,
        SuperWallet $destination,
        string $amountDecimal
    ): PreparedEvmPayout;

    public function broadcastNative(PreparedEvmPayout $payout): EvmPayoutResult;
}
