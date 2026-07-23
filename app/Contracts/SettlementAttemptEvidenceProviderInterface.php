<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Data\SettlementReconciliationResult;
use App\Models\MerchantSettlementAttempt;

interface SettlementAttemptEvidenceProviderInterface
{
    public function inspect(MerchantSettlementAttempt $attempt): SettlementReconciliationResult;
}
