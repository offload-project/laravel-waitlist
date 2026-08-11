<?php

declare(strict_types=1);

namespace OffloadProject\Waitlist\Listeners;

use OffloadProject\Waitlist\Events\WaitlistEntryAdded;
use OffloadProject\Waitlist\Events\WaitlistEntryVerified;
use OffloadProject\Waitlist\Jobs\SyncEntryToMailingList;

final class SubscribeEntryToMailingList
{
    public function handle(WaitlistEntryAdded|WaitlistEntryVerified $event): void
    {
        if (! config('waitlist.mailing_list.enabled', false)) {
            return;
        }

        if (! config('waitlist.mailing_list.auto_subscribe', true)) {
            return;
        }

        $awaitingVerification = (bool) config('waitlist.verification.enabled', false);

        // With verification turned on we hold off until the address is
        // confirmed; without it, we subscribe as soon as the entry is added.
        if ($awaitingVerification !== ($event instanceof WaitlistEntryVerified)) {
            return;
        }

        SyncEntryToMailingList::dispatchFor($event->entry);
    }
}
