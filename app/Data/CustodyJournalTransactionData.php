<?php

declare(strict_types=1);

namespace App\Data;

use DateTimeInterface;

final readonly class CustodyJournalTransactionData
{
    /**
     * @param  list<CustodyPostingData>  $postings
     * @param  array<string, mixed>  $immutableMetadata
     */
    public function __construct(
        public string $idempotencyKey,
        public string $eventType,
        public string $assetKey,
        public string $networkKey,
        public array $postings,
        public ?int $merchantId = null,
        public ?string $sourceReference = null,
        public ?DateTimeInterface $effectiveAt = null,
        public ?string $reason = null,
        public array $immutableMetadata = [],
        public ?int $reversalOfId = null,
    ) {}
}
