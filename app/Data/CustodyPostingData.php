<?php

declare(strict_types=1);

namespace App\Data;

final readonly class CustodyPostingData
{
    public function __construct(
        public int $accountId,
        public string $side,
        public string $amount,
        public ?string $amountAtomic = null,
    ) {}
}
