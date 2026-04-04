<?php
declare(strict_types=1);

namespace App\Data;

final readonly class InvoiceAddressContext
{
    public function __construct(
        public string $publicId,
        public ?string $externalId = null,
        public array $metadata = [],
    )
    {
    }

    public function label(): string
    {
        return "inv:{$this->publicId}";
    }
}
