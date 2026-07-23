<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Data\EvmPayoutResult;
use App\Data\EvmSweepSource;
use App\Data\PreparedErc20Payout;
use App\Models\Invoice;
use App\Models\SuperWallet;

interface EvmTokenPayoutSenderInterface
{
    public function prepareToken(
        Invoice $invoice,
        EvmSweepSource $source,
        SuperWallet $destination,
        string $amountDecimal
    ): PreparedErc20Payout;

    public function broadcastToken(PreparedErc20Payout $payout): EvmPayoutResult;
}
