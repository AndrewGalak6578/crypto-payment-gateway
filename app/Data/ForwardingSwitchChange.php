<?php

declare(strict_types=1);

namespace App\Data;

use App\Models\ForwardingSwitchEvent;

final readonly class ForwardingSwitchChange
{
    public function __construct(
        public ForwardingSwitchEvent $event,
        public bool $changed,
    ) {}
}
