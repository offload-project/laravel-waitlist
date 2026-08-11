<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use OffloadProject\Waitlist\Events\WaitlistCreated;
use OffloadProject\Waitlist\Events\WaitlistEntryAdded;
use OffloadProject\Waitlist\Events\WaitlistEntryInvited;
use OffloadProject\Waitlist\Events\WaitlistEntryRejected;
use OffloadProject\Waitlist\Events\WaitlistEntryVerified;
use OffloadProject\Waitlist\Facades\Waitlist;

test('creating a waitlist fires an event', function () {
    Event::fake([WaitlistCreated::class]);

    $beta = Waitlist::create('Beta', 'beta');

    Event::assertDispatched(WaitlistCreated::class, fn (WaitlistCreated $event) => $event->waitlist->is($beta));
});

test('adding an entry fires an event', function () {
    Event::fake([WaitlistEntryAdded::class]);

    $entry = Waitlist::add('John Doe', 'john@example.com');

    Event::assertDispatched(WaitlistEntryAdded::class, fn (WaitlistEntryAdded $event) => $event->entry->is($entry));
});

test('verifying an entry fires an event', function () {
    Event::fake([WaitlistEntryVerified::class]);

    $entry = Waitlist::add('John Doe', 'john@example.com');
    $entry->generateVerificationToken();

    Waitlist::verify($entry->verification_token);

    Event::assertDispatched(WaitlistEntryVerified::class, fn (WaitlistEntryVerified $event) => $event->entry->is($entry));
});

test('inviting an entry fires an event', function () {
    Notification::fake();
    Event::fake([WaitlistEntryInvited::class]);

    $entry = Waitlist::add('John Doe', 'john@example.com');
    Waitlist::invite($entry);

    Event::assertDispatched(WaitlistEntryInvited::class, fn (WaitlistEntryInvited $event) => $event->entry->is($entry));
});

test('rejecting an entry fires an event', function () {
    Event::fake([WaitlistEntryRejected::class]);

    $entry = Waitlist::add('John Doe', 'john@example.com');
    Waitlist::reject($entry);

    Event::assertDispatched(WaitlistEntryRejected::class, fn (WaitlistEntryRejected $event) => $event->entry->is($entry));
});
