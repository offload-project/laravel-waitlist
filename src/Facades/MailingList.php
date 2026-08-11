<?php

declare(strict_types=1);

namespace OffloadProject\Waitlist\Facades;

use Closure;
use Illuminate\Support\Facades\Facade;
use OffloadProject\Waitlist\Contracts\MailingListDriver;
use OffloadProject\Waitlist\MailingList\Drivers\ArrayDriver;
use OffloadProject\Waitlist\MailingList\MailingListManager;
use OffloadProject\Waitlist\Models\Waitlist;
use OffloadProject\Waitlist\Models\WaitlistEntry;

/**
 * @method static bool enabled()
 * @method static string getDefaultDriver()
 * @method static MailingListDriver driver(string|null $name = null)
 * @method static MailingListDriver for(Waitlist|null $waitlist = null)
 * @method static string driverNameFor(Waitlist|null $waitlist = null)
 * @method static string|null listIdFor(Waitlist|null $waitlist = null)
 * @method static void tagEntry(WaitlistEntry $entry, list<string> $tags)
 * @method static MailingListManager extend(string $name, Closure $callback)
 * @method static MailingListManager forget(string|null $name = null)
 * @method static ArrayDriver fake(string|null $listId = null)
 *
 * @see MailingListManager
 */
final class MailingList extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'waitlist.mailing-list';
    }
}
