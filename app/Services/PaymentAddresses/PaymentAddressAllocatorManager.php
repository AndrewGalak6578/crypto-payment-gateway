<?php
declare(strict_types=1);

namespace App\Services\PaymentAddresses;

use App\Contracts\PaymentAddressAllocatorInterface;
use App\Support\Chains\ChainRegistry;
use RuntimeException;

class PaymentAddressAllocatorManager
{
    public function __construct(
        private readonly ChainRegistry $chains,
        private readonly UtxoPaymentAddressAllocator $utxoAllocator,
        private readonly EvmPaymentAddressAllocator $evmAllocator,
    )
    {
    }

    public function forNetwork(string $networkKey): PaymentAddressAllocatorInterface
    {
        return match ($this->chains->family($networkKey)) {
            'utxo' => $this->utxoAllocator,
            'evm' => $this->evmAllocator,
            default => throw new RuntimeException(
                "Unsupported payment address allocator family for network [{$networkKey}]"
            )
        };
    }
}
