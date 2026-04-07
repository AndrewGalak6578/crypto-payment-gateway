<?php
declare(strict_types=1);

namespace App\Contracts;

use App\Data\EvmPaymentDetectionResult;
use App\Models\Invoice;

interface EvmInvoiceMonitorInterface
{
    public function detect(Invoice $invoice, int $requiredConfirmations): EvmPaymentDetectionResult;
}
