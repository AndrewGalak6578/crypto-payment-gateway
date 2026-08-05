<?php

declare(strict_types=1);

namespace Tests\Feature\Forwarding;

use App\Models\ForwardingSwitchEvent;
use App\Services\Forwarding\ForwardingGate;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class ForwardingGateTest extends TestCase
{
    use DatabaseMigrations;

    public function test_initial_event_keeps_effective_state_disabled_when_config_is_true(): void
    {
        config()->set('forwarding.enabled', true);

        $state = app(ForwardingGate::class)->inspect();

        self::assertTrue($state->configValid);
        self::assertTrue($state->dbAvailable);
        self::assertFalse($state->effective());
        self::assertSame('system:migration', $state->latestEvent?->actor);
        self::assertSame('fail_closed_initialization', $state->latestEvent?->reason);
        self::assertFalse($state->latestEvent?->enabled);
    }

    #[DataProvider('strictConfigProvider')]
    public function test_only_native_boolean_true_can_enable_parent_gate(mixed $value, bool $effective): void
    {
        ForwardingSwitchEvent::query()->create([
            'enabled' => true,
            'actor' => 'test:strict-config',
            'reason' => 'strict_config_matrix',
            'created_at' => now('UTC'),
        ]);
        config()->set('forwarding.enabled', $value);

        $state = app(ForwardingGate::class)->inspect();

        self::assertSame(is_bool($value), $state->configValid);
        self::assertSame($effective, $state->effective());
    }

    public function test_db_read_failure_is_fail_closed_and_visible(): void
    {
        config()->set('forwarding.enabled', true);
        DB::statement('SET search_path TO pg_catalog');

        try {
            $state = app(ForwardingGate::class)->inspect();
        } finally {
            DB::statement('SET search_path TO public');
        }

        self::assertFalse($state->dbAvailable);
        self::assertFalse($state->effective());
        self::assertSame('forwarding_switch_state_unavailable', $state->reason());
        self::assertNotNull($state->error);
    }

    /** @return array<string, array{mixed, bool}> */
    public static function strictConfigProvider(): array
    {
        return [
            'boolean true' => [true, true],
            'boolean false' => [false, false],
            'typo flase' => ['flase', false],
            'string true' => ['true', false],
            'string false' => ['false', false],
            'string one' => ['1', false],
            'integer one' => [1, false],
            'null' => [null, false],
            'zero' => [0, false],
            'float' => [1.0, false],
        ];
    }
}
