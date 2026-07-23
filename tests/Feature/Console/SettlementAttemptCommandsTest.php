<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class SettlementAttemptCommandsTest extends TestCase
{
    use RefreshDatabase;

    public function test_settlement_recovery_commands_are_registered(): void
    {
        self::assertSame(0, Artisan::call('settlements:reconcile-attempts', ['--limit' => 1]));
        self::assertSame(0, Artisan::call('settlements:reconcile-gas-fundings', ['--limit' => 1]));
        self::assertSame(0, Artisan::call('settlements:reap-reservations', ['--limit' => 1]));
        self::assertSame(0, Artisan::call('webhooks:dispatch-pending', ['--limit' => 1]));
    }
}
