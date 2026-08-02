<?php

declare(strict_types=1);

namespace App\Services\Custody;

use App\Exceptions\CustodyAccountingException;
use App\Models\CustodyPhase2ACutover;
use Illuminate\Support\Facades\DB;

final class Phase2AGate
{
    public const ADVISORY_LOCK_KEY = '731247380491024681';

    /**
     * Acquires the phase-wide shared transaction lock and returns whether the
     * immutable marker requires the Phase 2A shadow flow.
     */
    public function shadowRequiredForPositiveCredit(): bool
    {
        if (DB::transactionLevel() < 1) {
            throw new CustodyAccountingException(
                'Positive internal credits require an outer database transaction.',
            );
        }

        if (DB::connection()->getDriverName() !== 'pgsql') {
            throw new CustodyAccountingException('Custody Phase 2A requires PostgreSQL.');
        }

        DB::select(
            'SELECT pg_advisory_xact_lock_shared(CAST(? AS bigint))',
            [self::ADVISORY_LOCK_KEY],
        );

        $markerExists = CustodyPhase2ACutover::query()
            ->whereKey(CustodyPhase2ACutover::PHASE_KEY)
            ->exists();
        $gates = $this->activationConfig();

        if (! $markerExists) {
            if ($gates['custody_phase2a_shadow_internal_credits_enabled']) {
                throw new CustodyAccountingException(
                    'Phase 2A shadow credits are enabled before immutable cutover activation.',
                );
            }

            return false;
        }

        if ($gates !== self::requiredActivationConfig()) {
            throw new CustodyAccountingException(
                'Post-cutover custody gates are invalid; positive internal credit failed closed.',
            );
        }

        return true;
    }

    /**
     * @return array<string, bool>
     */
    public function activationConfig(): array
    {
        $config = [
            'custody_accounting_enabled' => config('custody.accounting_enabled', false),
            'custody_invoice_routing_enabled' => config('custody.invoice_routing_enabled', false),
            'custody_journal_writes_enabled' => config('custody.journal_writes_enabled', false),
            'custody_phase2a_shadow_internal_credits_enabled' => config(
                'custody.phase2a_shadow_internal_credits_enabled',
                false,
            ),
            'payout_automatic_requests_enabled' => config(
                'custody.payout_automatic_requests_enabled',
                false,
            ),
            'payout_execution_enabled' => config('custody.payout_execution_enabled', false),
            'payout_requests_enabled' => config('custody.payout_requests_enabled', false),
        ];

        foreach ($config as $gate => $value) {
            if (! is_bool($value)) {
                throw new CustodyAccountingException(
                    "Phase 2A activation gate [{$gate}] must be a Boolean.",
                );
            }
        }

        /** @var array<string, bool> $config */
        return $config;
    }

    /**
     * @return array<string, bool>
     */
    public static function requiredActivationConfig(): array
    {
        return [
            'custody_accounting_enabled' => true,
            'custody_invoice_routing_enabled' => false,
            'custody_journal_writes_enabled' => true,
            'custody_phase2a_shadow_internal_credits_enabled' => true,
            'payout_automatic_requests_enabled' => false,
            'payout_execution_enabled' => false,
            'payout_requests_enabled' => false,
        ];
    }
}
