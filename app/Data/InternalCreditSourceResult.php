<?php

declare(strict_types=1);

namespace App\Data;

use App\Models\MerchantSettlementEntry;

final readonly class InternalCreditSourceResult
{
    public function __construct(
        public MerchantSettlementEntry $entry,
        public bool $createdInCurrentTransaction,
    ) {}
}
