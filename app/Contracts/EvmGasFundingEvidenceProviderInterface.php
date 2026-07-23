<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Data\EvmGasFundingReconciliationResult;
use App\Models\EvmGasFunding;

interface EvmGasFundingEvidenceProviderInterface
{
    public function inspect(EvmGasFunding $funding): EvmGasFundingReconciliationResult;
}
