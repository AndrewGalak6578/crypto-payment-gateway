<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

final class StaleSettlementPreferenceException extends RuntimeException
{
    public function __construct(public readonly int $currentRevision)
    {
        parent::__construct('Settlement policy was changed by another request.');
    }
}
