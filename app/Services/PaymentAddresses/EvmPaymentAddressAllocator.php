<?php

namespace App\Services\PaymentAddresses;

use App\Contracts\DerivationIndexStoreInterface;
use App\Contracts\EvmAddressDeriverInterface;
use App\Contracts\PaymentAddressAllocatorInterface;
use App\Data\InvoiceAddressContext;
use App\Models\Invoice;
use App\Models\Merchant;
use App\Models\PaymentAddress;
use Illuminate\Database\QueryException;
use RuntimeException;

class EvmPaymentAddressAllocator implements PaymentAddressAllocatorInterface
{

    public function __construct(
        private readonly DerivationIndexStoreInterface $indexStore,
        private readonly EvmAddressDeriverInterface $deriver,
    )
    {
    }

    public function allocate(Merchant $merchant, string $assetKey, string $networkKey, InvoiceAddressContext $context): PaymentAddress
    {
        $assetKey = strtolower($assetKey);
        $networkKey = strtolower($networkKey);

        $keyRef = $this->resolveKeyRef($merchant, $networkKey, $assetKey);
        $pathTemplate = (string)config(
            'payment_addresses.evm.derivation_path_template',
            "m/44'/60'/0'/0/%d"
        );

        $maxAttempts = 3;
        $attempt = 1;

        while ($attempt <= $maxAttempts) {
            $index = $this->indexStore->reserveNext('evm', $networkKey, $keyRef);
            $derived = $this->deriver->derive($networkKey, $keyRef, $index, $pathTemplate);

            try {
                return PaymentAddress::create([
                    'merchant_id' => $merchant->id,
                    'invoice_id' => null,
                    'network_key' => $networkKey,
                    'asset_key' => $assetKey,
                    'address' => strtolower($derived->address),
                    'family' => 'evm',
                    'address_type' => 'deposit',
                    'strategy' => (($derived->meta['temporary'] ?? false) === true)
                        ? 'rpc_accounts_dev'
                        : 'hd_derived',
                    'status' => 'allocated',
                    'derivation_path' => $derived->derivationPath,
                    'derivation_index' => $derived->derivationIndex,
                    'key_ref' => $derived->keyRef ?? $keyRef,
                    'issued_at' => now('UTC'),
                    'meta' => array_merge($derived->meta, [
                        'label' => $context->label(),
                        'external_id' => $context->externalId,
                    ]),
                ]);
            } catch (QueryException $exception) {
                if ($this->isUniqueConstraintViolation($exception) === false || $attempt === $maxAttempts) {
                    throw new RuntimeException(
                        "Unable to allocate unique EVM payment address for [{$networkKey}] after [{$attempt}] attempt(s).",
                        previous: $exception
                    );
                }
            }

            $attempt++;
        }

        throw new RuntimeException("Unable to allocate unique EVM payment address for [{$networkKey}].");
    }

    public function attachToInvoice(PaymentAddress $paymentAddress, Invoice $invoice): PaymentAddress
    {
        $paymentAddress->markAssignedToInvoice($invoice);

        return $paymentAddress->refresh();
    }

    private function resolveKeyRef(Merchant $merchant, string $networkKey, string $assetKey): string
    {
        $merchantSpecific = config("payment_addresses.evm.merchant_key_refs.{$merchant->id}.{$networkKey}.{$assetKey}");

        if (is_string($merchantSpecific) && $merchantSpecific !== '') {
            return $merchantSpecific;
        }

        $assetSpecific = config("payment_addresses.evm.network_key_refs.{$networkKey}.{$assetKey}");
        if (is_string($assetSpecific) && $assetSpecific !== '') {
            return $assetSpecific;
        }

        $networkDefault = config("payment_addresses.evm.default_key_refs.{$networkKey}");
        if (is_string($networkDefault) && $networkDefault !== '') {
            return $networkDefault;
        }

        throw new RuntimeException(
            "Missing EVM key_ref for network [{$networkKey}] asset [{$assetKey}]. " .
            'Configure payment_addresses.evm key refs before enabling EVM invoice allocation.'
        );
    }

    private function isUniqueConstraintViolation(QueryException $exception): bool
    {
        $sqlState = (string)($exception->errorInfo[0] ?? '');

        return $sqlState === '23000' || $sqlState === '23505';
    }
}
