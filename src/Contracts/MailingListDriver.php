<?php

declare(strict_types=1);

namespace OffloadProject\Waitlist\Contracts;

use OffloadProject\Waitlist\Exceptions\MailingListException;
use OffloadProject\Waitlist\MailingList\Subscriber;
use OffloadProject\Waitlist\Models\WaitlistEntry;

interface MailingListDriver
{
    /**
     * The name this driver is registered under.
     */
    public function name(): string;

    /**
     * Add (or update) the entry on the given list.
     *
     * The $listId is provider specific: a Mailchimp audience id, a Kit form
     * or tag id, and so on.
     *
     * @param  array{tags?: array<array-key, string>, double_optin?: bool, attributes?: array<string, mixed>}  $options
     *
     * @throws MailingListException
     */
    public function subscribe(WaitlistEntry $entry, string $listId, array $options = []): Subscriber;

    /**
     * Unsubscribe the entry from the given list.
     *
     * @throws MailingListException
     */
    public function unsubscribe(WaitlistEntry $entry, string $listId): void;

    /**
     * Apply tags to the entry on the given list.
     *
     * @param  list<string>  $tags
     *
     * @throws MailingListException
     */
    public function tag(WaitlistEntry $entry, string $listId, array $tags): void;

    /**
     * Look up a subscriber by email, or null when they are not on the list.
     *
     * @throws MailingListException
     */
    public function find(string $email, string $listId): ?Subscriber;
}
