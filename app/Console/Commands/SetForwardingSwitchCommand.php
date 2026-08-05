<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Forwarding\ForwardingSwitchManager;
use Illuminate\Console\Command;
use Throwable;

final class SetForwardingSwitchCommand extends Command
{
    protected $signature = 'forwarding:switch
        {state : enable or disable}
        {--actor= : Non-empty operator or automation identity}
        {--reason= : Non-empty audit reason}';

    protected $description = 'Append an idempotent event to the distributed forwarding kill switch';

    public function handle(ForwardingSwitchManager $switch): int
    {
        $state = strtolower(trim((string) $this->argument('state')));
        $actor = trim((string) $this->option('actor'));
        $reason = trim((string) $this->option('reason'));

        if (! in_array($state, ['enable', 'disable'], true)) {
            $this->error('State must be exactly enable or disable.');

            return self::INVALID;
        }

        if ($actor === '' || $reason === '') {
            $this->error('Both --actor and --reason are required and must be non-empty.');

            return self::INVALID;
        }

        try {
            $result = $switch->set($state === 'enable', $actor, $reason);
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf(
            '%s event %d: forwarding %s.',
            $result->changed ? 'Created' : 'Existing',
            $result->event->id,
            $result->event->enabled ? 'enabled' : 'disabled',
        ));

        return self::SUCCESS;
    }
}
