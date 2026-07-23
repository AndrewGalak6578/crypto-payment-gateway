<?php

declare(strict_types=1);

namespace App\Data;

use App\Models\Invoice;

final readonly class SettlementCompletionResult
{
    public function __construct(
        public Invoice $invoice,
        public bool $completedNow,
    ) {}
}
