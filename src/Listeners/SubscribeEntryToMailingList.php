<?php

declare(strict_types=1);

namespace OffloadProject\Waitlist\Listeners;

use Illuminate\Support\Facades\Log;
use OffloadProject\Waitlist\Events\WaitlistEntryAdded;
use OffloadProject\Waitlist\Events\WaitlistEntryVerified;
use OffloadProject\Waitlist\Jobs\SyncEntryToMailingList;

/**
 * Puts a new — or newly verified — entry on the mailing list.
 *
 * Every reason this decides *not* to sync is logged. A sync that never starts
 * leaves no job, no failure and no event, so from the outside it is
 * indistinguishable from one that succeeded: the entry simply never appears in
 * the provider. Naming the config key that stopped it turns a silent
 * misconfiguration into a line somebody can search for.
 */
final class SubscribeEntryToMailingList
{
    public function handle(WaitlistEntryAdded|WaitlistEntryVerified $event): void
    {
        $context = [
            'entry_id' => $event->entry->getKey(),
            'waitlist' => $event->entry->waitlist?->slug,
        ];

        if (! config('waitlist.mailing_list.enabled', false)) {
            Log::info(
                'Waitlist entry not synced: the mailing list integration is off. Set waitlist.mailing_list.enabled to change this.',
                $context,
            );

            return;
        }

        if (! config('waitlist.mailing_list.auto_subscribe', true)) {
            Log::info(
                'Waitlist entry not synced: automatic subscribing is off. Set waitlist.mailing_list.auto_subscribe to change this, or sync it by hand with Waitlist::subscribe().',
                $context,
            );

            return;
        }

        $awaitingVerification = (bool) config('waitlist.verification.enabled', false);

        // With verification turned on we hold off until the address is
        // confirmed; without it, we subscribe as soon as the entry is added.
        if ($awaitingVerification !== ($event instanceof WaitlistEntryVerified)) {
            /*
             * Only one side of this is worth a line. An unverified entry is
             * waiting for something that may never happen, and that is the case
             * people come looking for. The other side — a verification event
             * arriving while verification is off — is a no-op by design,
             * because the entry was already synced when it was added.
             */
            if ($awaitingVerification) {
                Log::info(
                    'Waitlist entry not synced yet: it is held until the email address is verified.',
                    $context,
                );
            }

            return;
        }

        SyncEntryToMailingList::dispatchFor($event->entry);
    }
}
