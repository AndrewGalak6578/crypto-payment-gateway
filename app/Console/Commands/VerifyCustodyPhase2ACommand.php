<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Custody\Phase2AVerifier;
use Illuminate\Console\Command;

final class VerifyCustodyPhase2ACommand extends Command
{
    protected $signature = 'custody:verify-phase2a {--json : Emit the complete parity report as JSON}';

    protected $description = 'Read-only verification of Phase 2A legacy/source/journal/projection parity';

    public function handle(Phase2AVerifier $verifier): int
    {
        $report = $verifier->verify();

        if ((bool) $this->option('json')) {
            $this->line(json_encode(
                $report,
                JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
            ));
        } else {
            $this->line(sprintf(
                'Phase 2A verification: sources=%d covered=%d issues=%d projection_drift=%d.',
                $report['parity']['completed_internal_credit_count'],
                $report['parity']['covered_internal_credit_count'],
                $report['issue_count'],
                $report['parity']['projection_drift_count'],
            ));
        }

        return $report['clean'] ? self::SUCCESS : self::FAILURE;
    }
}
