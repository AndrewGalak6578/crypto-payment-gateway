<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Forwarding\ForwardingGate;
use Illuminate\Console\Command;

final class ShowForwardingStatusCommand extends Command
{
    protected $signature = 'forwarding:status';

    protected $description = 'Show the strict config parent gate, DB kill switch, and effective forwarding state';

    public function handle(ForwardingGate $gate): int
    {
        $state = $gate->inspect();
        $event = $state->latestEvent;

        $this->line('config_valid: '.($state->configValid ? 'yes' : 'no'));
        $this->line('config_value: '.$this->formatValue($state->configValue));
        $this->line('db_available: '.($state->dbAvailable ? 'yes' : 'no'));
        $this->line('db_event_id: '.($event?->id ?? 'missing'));
        $this->line('db_enabled: '.($event === null ? 'missing' : ($event->enabled ? 'true' : 'false')));
        $this->line('db_actor: '.($event?->actor ?? 'missing'));
        $this->line('db_reason: '.($event?->reason ?? 'missing'));
        $this->line('db_created_at: '.($event?->created_at?->toIso8601String() ?? 'missing'));
        $this->line('effective: '.($state->effective() ? 'enabled' : 'disabled'));
        $this->line('state_reason: '.$state->reason());

        if ($state->error !== null) {
            $this->error('error: '.$state->error);
        }

        return $state->configValid && $state->dbAvailable && $event !== null
            ? self::SUCCESS
            : self::FAILURE;
    }

    private function formatValue(mixed $value): string
    {
        return match (true) {
            $value === true => 'true (boolean)',
            $value === false => 'false (boolean)',
            $value === null => 'null',
            is_scalar($value) => var_export($value, true).' ('.get_debug_type($value).')',
            default => get_debug_type($value),
        };
    }
}
