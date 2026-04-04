<?php
declare(strict_types=1);

namespace App\Contracts;

use App\Data\InvoiceAddressContext;
use App\Models\Invoice;
use App\Models\Merchant;
use App\Models\PaymentAddress;

interface PaymentAddressAllocatorInterface
{
    public function allocate(
        Merchant $merchant,
        string $assetKey,
        string $networkKey,
        InvoiceAddressContext $context
    ): PaymentAddress;

    public function attachToInvoice(PaymentAddress $paymentAddress, Invoice $invoice): PaymentAddress;
}
