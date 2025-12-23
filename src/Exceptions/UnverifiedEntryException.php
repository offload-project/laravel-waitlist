<?php

declare(strict_types=1);

namespace OffloadProject\Waitlist\Exceptions;

use Exception;
use OffloadProject\Waitlist\Models\WaitlistEntry;

final class UnverifiedEntryException extends Exception
{
    public function __construct(
        public WaitlistEntry $entry,
        string $message = 'Cannot invite unverified waitlist entry.'
    ) {
        parent::__construct($message);
    }
}
