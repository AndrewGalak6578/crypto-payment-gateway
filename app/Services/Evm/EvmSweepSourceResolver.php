<?php
declare(strict_types=1);

namespace App\Services\Evm;

use App\Contracts\EvmSweepSourceResolverInterface;
use App\Data\EvmSweepSource;
use App\Models\Invoice;
use App\Models\PaymentAddress;
use App\Support\Chains\ChainRegistry;
use RuntimeException;

final class EvmSweepSourceResolver implements EvmSweepSourceResolverInterface
{
    public function __construct(
        private readonly ChainRegistry $chains,
    )
    {
    }

    public function resolveForInvoice(Invoice $invoice): EvmSweepSource
    {
        $paymentAddress = $invoice->paymentAddress;

        if (!$paymentAddress instanceof PaymentAddress) {
            throw new RuntimeException(
                "Invoice [{$invoice->id}] has no linked payment address record."
            );
        }

        $networkKey = strtolower($paymentAddress->network_key);
        $family = $this->chains->family($networkKey);

        if ($family !== 'evm') {
            throw new RuntimeException(
                "Invoice [{$invoice->id}] payment address network [{$networkKey}] is not EVM."
            );
        }

        $keyRef = (string)($paymentAddress->key_ref ?? '');
        if ($keyRef === '') {
            throw new RuntimeException(
                "PaymentAddress [{$paymentAddress->id}] has no key ref. " .
                'EVM settlement requires a resolvable signer source.'
            );
        }

        $address = strtolower((string)$paymentAddress->address);
        if ($address === '') {
            throw new RuntimeException(
                "PaymentAddress [{$paymentAddress->id}] has empty address."
            );
        }

        return new EvmSweepSource(
            networkKey: $networkKey,
            address: $address,
            keyRef: $keyRef,
            derivationPath: $paymentAddress->derivation_path,
            derivationIndex: $paymentAddress->derivation_index !== null
                ? (int)$paymentAddress->derivation_index
                : null,
            strategy: (string)($paymentAddress->strategy ?: 'hd_derived'),
            meta: [
                'payment_address_id' => $paymentAddress->id,
                'invoice_id' => $invoice->id,
                'asset_key' => $paymentAddress->asset_key,
            ]
        );
    }
}
