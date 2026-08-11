<?php

declare(strict_types=1);

namespace OffloadProject\Waitlist\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use OffloadProject\Waitlist\Models\WaitlistEntry;

final class WaitlistEntryUnsubscribed
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly WaitlistEntry $entry,
        public readonly string $driver,
    ) {}
}
