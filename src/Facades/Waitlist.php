<?php

declare(strict_types=1);

namespace OffloadProject\Waitlist\Facades;

use Illuminate\Support\Facades\Facade;
use OffloadProject\Waitlist\Models\WaitlistEntry;

/**
 * @method static WaitlistEntry|null verify(string $token)
 * @method static WaitlistEntry sendVerification(int|WaitlistEntry $entry)
 *
 * @see \OffloadProject\Waitlist\WaitlistService
 */
final class Waitlist extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'waitlist';
    }
}
