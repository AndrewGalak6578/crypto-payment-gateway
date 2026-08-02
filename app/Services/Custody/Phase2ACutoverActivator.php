<?php

declare(strict_types=1);

namespace App\Services\Custody;

use App\Exceptions\CustodyAccountingException;
use App\Models\CustodyPhase2ACutover;
use Illuminate\Support\Facades\DB;

final readonly class Phase2ACutoverActivator
{
    public function __construct(
        private Phase2AGate $gate,
        private Phase2AVerifier $verifier,
        private CustodyCanonicalPayload $canonical,
    ) {}

    public function activate(string $activationReference): CustodyPhase2ACutover
    {
        if (
            $activationReference === ''
            || trim($activationReference) !== $activationReference
            || mb_strlen($activationReference) > 191
        ) {
            throw new CustodyAccountingException('Phase 2A activation reference is not canonical.');
        }

        if (DB::transactionLevel() > 0) {
            if (! app()->environment('testing')) {
                throw new CustodyAccountingException(
                    'Phase 2A cutover activation requires its own outer transaction.',
                );
            }

            return $this->activateInCurrentTransaction($activationReference, false);
        }

        return DB::transaction(
            fn (): CustodyPhase2ACutover => $this->activateInCurrentTransaction(
                $activationReference,
                true,
            ),
            5,
        );
    }

    private function activateInCurrentTransaction(
        string $activationReference,
        bool $setIsolation,
    ): CustodyPhase2ACutover {
        if ($setIsolation) {
            DB::statement('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
        }

        DB::select(
            'SELECT pg_advisory_xact_lock(CAST(? AS bigint))',
            [Phase2AGate::ADVISORY_LOCK_KEY],
        );

        /** @var CustodyPhase2ACutover|null $existing */
        $existing = CustodyPhase2ACutover::query()
            ->whereKey(CustodyPhase2ACutover::PHASE_KEY)
            ->first();
        if ($existing !== null) {
            return $existing;
        }

        $activationConfig = $this->gate->activationConfig();
        if ($activationConfig !== Phase2AGate::requiredActivationConfig()) {
            throw new CustodyAccountingException(
                'Phase 2A cutover requires the exact seven-gate activation configuration.',
            );
        }

        $verification = $this->verifier->verifySnapshot();
        $baselinePayload = $this->verifier->zeroBaselinePayload($verification);
        $this->assertZeroBaseline($baselinePayload);
        $baselineCanonical = $this->canonical->json($baselinePayload);
        $configCanonical = $this->canonical->json($activationConfig);
        $activatedAt = now('UTC');

        DB::table('custody_phase2a_cutovers')->insert([
            'phase_key' => CustodyPhase2ACutover::PHASE_KEY,
            'activated_at' => $activatedAt,
            'activation_reference' => $activationReference,
            'baseline_verification_canonical_text' => $baselineCanonical,
            'baseline_verification_hash' => hash('sha256', $baselineCanonical),
            'activation_config_canonical_text' => $configCanonical,
            'activation_config_fingerprint' => hash('sha256', $configCanonical),
            'created_at' => $activatedAt,
        ]);

        /** @var CustodyPhase2ACutover $marker */
        $marker = CustodyPhase2ACutover::query()
            ->whereKey(CustodyPhase2ACutover::PHASE_KEY)
            ->firstOrFail();

        return $marker;
    }

    /**
     * @param  array<string, mixed>  $baseline
     */
    private function assertZeroBaseline(array $baseline): void
    {
        $expected = [
            'baseline_schema_version' => 'custody_phase2a_zero_parity_baseline_v1',
            'counts' => [
                'custody_account_balances' => 0,
                'custody_accounts' => 0,
                'custody_journal_entries' => 0,
                'custody_journal_postings' => 0,
                'custody_journal_source_links' => 0,
                'custody_phase2a_cutovers' => 0,
                'merchant_balances' => 0,
                'merchant_settlement_entries' => 0,
            ],
            'parity' => [
                'completed_internal_credit_count' => 0,
                'covered_internal_credit_count' => 0,
                'projection_drift_count' => 0,
                'source_snapshot_mutation_count' => 0,
                'uncovered_internal_credit_count' => 0,
                'unexplained_legacy_residual_count' => 0,
            ],
        ];

        if ($baseline !== $expected) {
            throw new CustodyAccountingException('Phase 2A cutover requires the exact zero/parity baseline.');
        }
    }
}
