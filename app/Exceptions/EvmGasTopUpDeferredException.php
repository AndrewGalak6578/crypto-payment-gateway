<?php
declare(strict_types=1);

namespace App\Exceptions;

use App\Data\EvmGasTopUpOutcome;
use RuntimeException;

final class EvmGasTopUpDeferredException extends RuntimeException
{
    public function __construct(
        public readonly EvmGasTopUpOutcome $outcome,
    )
    {
        parent::__construct('ERC-20 payout deferred: native gas top-up in progress.');
    }
}
