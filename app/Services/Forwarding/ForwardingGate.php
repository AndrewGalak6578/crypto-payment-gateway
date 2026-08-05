<?php

declare(strict_types=1);

namespace App\Services\Forwarding;

use App\Data\ForwardingGateState;
use App\Exceptions\ForwardingConfigurationException;
use App\Exceptions\ForwardingSwitchUnavailableException;
use App\Models\ForwardingSwitchEvent;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

final class ForwardingGate
{
    public const ADVISORY_LOCK_KEY = 731247380491024682;

    public function inspect(): ForwardingGateState
    {
        $configValue = config('forwarding.enabled');
        $configValid = is_bool($configValue);

        try {
            $event = ForwardingSwitchEvent::query()->latest('id')->first();

            return new ForwardingGateState(
                configValue: $configValue,
                configValid: $configValid,
                dbAvailable: true,
                latestEvent: $event,
            );
        } catch (Throwable $exception) {
            return new ForwardingGateState(
                configValue: $configValue,
                configValid: $configValid,
                dbAvailable: false,
                latestEvent: null,
                error: $exception->getMessage(),
            );
        }
    }

    public function inspectWithSharedLock(): ForwardingGateState
    {
        if (DB::transactionLevel() < 1) {
            throw new RuntimeException('The authoritative forwarding gate requires an active database transaction.');
        }

        $this->acquireSharedLock();

        return $this->inspect();
    }

    public function acquireSharedLock(): void
    {
        $this->assertPostgreSql();

        DB::select(
            'SELECT pg_advisory_xact_lock_shared(CAST(? AS bigint))',
            [self::ADVISORY_LOCK_KEY],
        );
    }

    public function acquireExclusiveLock(): void
    {
        if (DB::transactionLevel() < 1) {
            throw new RuntimeException('The forwarding switch exclusive lock requires an active database transaction.');
        }

        $this->assertPostgreSql();

        DB::select(
            'SELECT pg_advisory_xact_lock(CAST(? AS bigint))',
            [self::ADVISORY_LOCK_KEY],
        );
    }

    public function throwIfOperationalFailure(ForwardingGateState $state): void
    {
        if (! $state->configValid) {
            throw new ForwardingConfigurationException(
                'forwarding.enabled must be a native PHP Boolean; forwarding is fail-closed.',
            );
        }

        if (! $state->dbAvailable) {
            throw new ForwardingSwitchUnavailableException(
                'Forwarding switch state is unavailable; forwarding is fail-closed.'
                .($state->error !== null ? ' '.$state->error : ''),
            );
        }
    }

    private function assertPostgreSql(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            throw new RuntimeException('The forwarding safety boundary requires PostgreSQL advisory locks.');
        }
    }
}
