<?php
declare(strict_types=1);

namespace App\Services\PaymentAddresses;

use App\Contracts\PaymentAddressAllocatorInterface;
use App\Data\InvoiceAddressContext;
use App\Models\Invoice;
use App\Models\Merchant;
use App\Models\PaymentAddress;
use App\Support\Coin;

final class UtxoPaymentAddressAllocator implements PaymentAddressAllocatorInterface
{

    public function allocate(Merchant $merchant, string $assetKey, string $networkKey, InvoiceAddressContext $context): PaymentAddress
    {
        $assetKey = strtolower($assetKey);
        $networkKey = strtolower($networkKey);

        $rpc = Coin::rpc($assetKey);
        $address = $rpc->getNewAddress($context->label());

        return PaymentAddress::create([
            'merchant_id' => $merchant->id,
            'invoice_id' => null,
            'network_key' => $networkKey,
            'asset_key' => $assetKey,
            'address' => $address,
            'family' => 'utxo',
            'address_type' => 'deposit',
            'strategy' => 'utxo_rpc',
            'status' => 'allocated',
            'issued_at' => now('UTC'),
            'meta' => [
                'label' => $context->label(),
                'external_id' => $context->externalId,
            ]
        ]);
    }

    public function attachToInvoice(PaymentAddress $paymentAddress, Invoice $invoice): PaymentAddress
    {
        $paymentAddress->markAssignedToInvoice($invoice);

        return $paymentAddress->refresh();
    }
}
