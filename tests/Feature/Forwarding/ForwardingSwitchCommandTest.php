<?php

declare(strict_types=1);

namespace Tests\Feature\Forwarding;

use App\Models\ForwardingSwitchEvent;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class ForwardingSwitchCommandTest extends TestCase
{
    use DatabaseMigrations;

    public function test_migration_creates_exact_initial_disabled_event(): void
    {
        $event = ForwardingSwitchEvent::query()->sole();

        self::assertSame(1, $event->id);
        self::assertFalse($event->enabled);
        self::assertSame('system:migration', $event->actor);
        self::assertSame('fail_closed_initialization', $event->reason);
        self::assertNotNull($event->created_at);
    }

    public function test_enable_disable_are_ordered_and_repeated_state_is_idempotent(): void
    {
        config()->set('forwarding.enabled', true);

        self::assertSame(Command::SUCCESS, Artisan::call('forwarding:switch', [
            'state' => 'enable',
            '--actor' => 'operator:alice',
            '--reason' => 'approved_test_enable',
        ]));
        self::assertSame(2, ForwardingSwitchEvent::query()->count());

        self::assertSame(Command::SUCCESS, Artisan::call('forwarding:switch', [
            'state' => 'enable',
            '--actor' => 'operator:alice',
            '--reason' => 'idempotent_repeat',
        ]));
        self::assertSame(2, ForwardingSwitchEvent::query()->count());

        self::assertSame(Command::SUCCESS, Artisan::call('forwarding:switch', [
            'state' => 'disable',
            '--actor' => 'operator:bob',
            '--reason' => 'incident_stop',
        ]));

        $events = ForwardingSwitchEvent::query()->orderBy('id')->get();
        self::assertCount(3, $events);
        self::assertSame([false, true, false], $events->pluck('enabled')->all());
        self::assertSame(['system:migration', 'operator:alice', 'operator:bob'], $events->pluck('actor')->all());
    }

    public function test_actor_reason_and_state_are_required_and_non_empty(): void
    {
        config()->set('forwarding.enabled', true);

        self::assertSame(Command::INVALID, Artisan::call('forwarding:switch', [
            'state' => 'enable',
            '--actor' => '',
            '--reason' => 'reason',
        ]));
        self::assertSame(Command::INVALID, Artisan::call('forwarding:switch', [
            'state' => 'enable',
            '--actor' => 'actor',
            '--reason' => '   ',
        ]));
        self::assertSame(Command::INVALID, Artisan::call('forwarding:switch', [
            'state' => 'invalid',
            '--actor' => 'actor',
            '--reason' => 'reason',
        ]));
        self::assertSame(1, ForwardingSwitchEvent::query()->count());
    }

    public function test_enable_rejects_false_or_invalid_config_but_disable_is_always_allowed(): void
    {
        foreach ([false, 'true', 'flase', 1, null] as $value) {
            config()->set('forwarding.enabled', $value);

            self::assertSame(Command::FAILURE, Artisan::call('forwarding:switch', [
                'state' => 'enable',
                '--actor' => 'operator:test',
                '--reason' => 'must_fail_closed',
            ]));
        }

        config()->set('forwarding.enabled', 'invalid');
        self::assertSame(Command::SUCCESS, Artisan::call('forwarding:switch', [
            'state' => 'disable',
            '--actor' => 'operator:test',
            '--reason' => 'disable_remains_available',
        ]));
        self::assertSame(1, ForwardingSwitchEvent::query()->count());
    }

    public function test_postgresql_trigger_rejects_update_and_delete(): void
    {
        $event = ForwardingSwitchEvent::query()->sole();

        try {
            DB::table('forwarding_switch_events')->where('id', $event->id)->update(['reason' => 'tampered']);
            self::fail('Append-only forwarding event was updated.');
        } catch (QueryException $exception) {
            self::assertStringContainsString('append-only', $exception->getMessage());
        }

        try {
            DB::table('forwarding_switch_events')->where('id', $event->id)->delete();
            self::fail('Append-only forwarding event was deleted.');
        } catch (QueryException $exception) {
            self::assertStringContainsString('append-only', $exception->getMessage());
        }

        self::assertSame(1, ForwardingSwitchEvent::query()->count());
    }

    public function test_status_command_reports_disabled_success_and_invalid_config_failure(): void
    {
        config()->set('forwarding.enabled', true);

        self::assertSame(Command::SUCCESS, Artisan::call('forwarding:status'));
        $disabledOutput = Artisan::output();
        self::assertStringContainsString('effective: disabled', $disabledOutput);
        self::assertStringContainsString('db_event_id: 1', $disabledOutput);

        config()->set('forwarding.enabled', 'true');

        self::assertSame(Command::FAILURE, Artisan::call('forwarding:status'));
        $invalidOutput = Artisan::output();
        self::assertStringContainsString('config_valid: no', $invalidOutput);
        self::assertStringContainsString('state_reason: forwarding_configuration_invalid', $invalidOutput);

        config()->set('forwarding.enabled', true);
        DB::statement('TRUNCATE TABLE forwarding_switch_events RESTART IDENTITY');

        self::assertSame(Command::FAILURE, Artisan::call('forwarding:status'));
        $missingOutput = Artisan::output();
        self::assertStringContainsString('db_event_id: missing', $missingOutput);
        self::assertStringContainsString('state_reason: forwarding_switch_event_missing', $missingOutput);
    }
}
