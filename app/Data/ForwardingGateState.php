<?php

declare(strict_types=1);

namespace App\Data;

use App\Models\ForwardingSwitchEvent;

final readonly class ForwardingGateState
{
    public function __construct(
        public mixed $configValue,
        public bool $configValid,
        public bool $dbAvailable,
        public ?ForwardingSwitchEvent $latestEvent,
        public ?string $error = null,
    ) {}

    public function configEnabled(): bool
    {
        return $this->configValid && $this->configValue === true;
    }

    public function effective(): bool
    {
        return $this->configEnabled()
            && $this->dbAvailable
            && $this->latestEvent?->enabled === true;
    }

    public function reason(): string
    {
        if (! $this->configValid) {
            return 'forwarding_configuration_invalid';
        }

        if (! $this->dbAvailable) {
            return 'forwarding_switch_state_unavailable';
        }

        if ($this->configValue !== true) {
            return 'forwarding_config_parent_disabled';
        }

        if ($this->latestEvent === null) {
            return 'forwarding_switch_event_missing';
        }

        if (! $this->latestEvent->enabled) {
            return 'forwarding_switch_disabled';
        }

        return 'forwarding_enabled';
    }
}
