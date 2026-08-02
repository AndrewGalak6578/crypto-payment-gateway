<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Custody\Phase2ACutoverActivator;
use Illuminate\Console\Command;
use Throwable;

final class ActivateCustodyPhase2ACutoverCommand extends Command
{
    protected $signature = 'custody:activate-phase2a
        {--reference= : Stable operator/deployment activation reference}';

    protected $description = 'Atomically activate the immutable reset-first Phase 2A cutover';

    public function handle(Phase2ACutoverActivator $activator): int
    {
        $reference = $this->option('reference');
        if (! is_string($reference) || $reference === '') {
            $this->error('The --reference option is required.');

            return self::INVALID;
        }

        try {
            $marker = $activator->activate($reference);
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Phase 2A cutover active: %s at %s (reference: %s).',
            $marker->phase_key,
            $marker->activated_at->utc()->format('Y-m-d\TH:i:s.u\Z'),
            $marker->activation_reference,
        ));

        return self::SUCCESS;
    }
}
