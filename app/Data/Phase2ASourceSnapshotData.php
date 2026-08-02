<?php

declare(strict_types=1);

namespace App\Data;

final readonly class Phase2ASourceSnapshotData
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public array $payload,
        public string $canonicalText,
        public string $hash,
        public int $assetScale,
        public string $amount,
        public string $amountAtomic,
    ) {}
}
