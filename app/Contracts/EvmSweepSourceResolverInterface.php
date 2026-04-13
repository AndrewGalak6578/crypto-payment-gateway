<?php
declare(strict_types=1);

namespace App\Contracts;

use App\Data\EvmSweepSource;
use App\Models\Invoice;

interface EvmSweepSourceResolverInterface
{
    public function resolveForInvoice(Invoice $invoice): EvmSweepSource;
}
