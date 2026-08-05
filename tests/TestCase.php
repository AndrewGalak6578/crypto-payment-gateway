<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function enableForwardingForTests(string $reason = 'test_setup'): void
    {
        config()->set('forwarding.enabled', true);

        if (! \Illuminate\Support\Facades\Schema::hasTable('forwarding_switch_events')) {
            return;
        }

        \App\Models\ForwardingSwitchEvent::query()->create([
            'enabled' => true,
            'actor' => 'test:suite',
            'reason' => $reason,
            'created_at' => now('UTC'),
        ]);
    }

    protected function disableForwardingForTests(string $reason = 'test_disable'): void
    {
        \App\Models\ForwardingSwitchEvent::query()->create([
            'enabled' => false,
            'actor' => 'test:suite',
            'reason' => $reason,
            'created_at' => now('UTC'),
        ]);
    }
}
