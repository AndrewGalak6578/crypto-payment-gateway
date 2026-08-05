<?php

declare(strict_types=1);

namespace App\Services\Forwarding;

use App\Data\ForwardingSwitchChange;
use App\Exceptions\ForwardingConfigurationException;
use App\Models\ForwardingSwitchEvent;
use Illuminate\Support\Facades\DB;

final readonly class ForwardingSwitchManager
{
    public function __construct(private ForwardingGate $gate) {}

    public function set(bool $enabled, string $actor, string $reason): ForwardingSwitchChange
    {
        $actor = trim($actor);
        $reason = trim($reason);

        if ($actor === '' || $reason === '') {
            throw new \InvalidArgumentException('Forwarding switch actor and reason must be non-empty.');
        }

        if ($enabled) {
            $state = $this->gate->inspect();
            if (! $state->configValid || $state->configValue !== true) {
                throw new ForwardingConfigurationException(
                    'The forwarding switch cannot be enabled unless forwarding.enabled is native Boolean true.',
                );
            }
        }

        return DB::transaction(function () use ($enabled, $actor, $reason): ForwardingSwitchChange {
            $this->gate->acquireExclusiveLock();

            $latest = ForwardingSwitchEvent::query()->latest('id')->first();
            if ($latest !== null && $latest->enabled === $enabled) {
                return new ForwardingSwitchChange($latest, false);
            }

            $event = ForwardingSwitchEvent::query()->create([
                'enabled' => $enabled,
                'actor' => $actor,
                'reason' => $reason,
                'created_at' => now('UTC'),
            ]);

            return new ForwardingSwitchChange($event, true);
        });
    }
}
