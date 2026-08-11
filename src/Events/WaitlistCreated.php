<?php

declare(strict_types=1);

namespace OffloadProject\Waitlist\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use OffloadProject\Waitlist\Models\Waitlist;

final class WaitlistCreated
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly Waitlist $waitlist
    ) {}
}
