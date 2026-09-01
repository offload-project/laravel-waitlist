<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use OffloadProject\Waitlist\Contracts\MailingListDriver;
use OffloadProject\Waitlist\Events\MailingListSyncFailed;
use OffloadProject\Waitlist\Events\WaitlistEntrySubscribed;
use OffloadProject\Waitlist\Events\WaitlistEntryUnsubscribed;
use OffloadProject\Waitlist\Events\WaitlistEntryVerified;
use OffloadProject\Waitlist\Exceptions\MailingListException;
use OffloadProject\Waitlist\Facades\MailingList;
use OffloadProject\Waitlist\Facades\Waitlist;
use OffloadProject\Waitlist\Jobs\SyncEntryToMailingList;
use OffloadProject\Waitlist\MailingList\Drivers\ArrayDriver;
use OffloadProject\Waitlist\MailingList\Subscriber;
use OffloadProject\Waitlist\Models\WaitlistEntry;

// Automatic syncing

test('entries are not synced while the integration is disabled', function () {
    $fake = MailingList::fake();
    config(['waitlist.mailing_list.enabled' => false]);

    Waitlist::add('John Doe', 'john@example.com');

    expect($fake->subscribers())->toBeEmpty();
});

test('it says why in the log when the integration is off', function () {
    MailingList::fake();
    config(['waitlist.mailing_list.enabled' => false]);

    $log = capturedLog();

    Waitlist::add('John Doe', 'john@example.com');

    expect($log)->toHaveLoggedOnce('waitlist.mailing_list.enabled');
});

test('it says why in the log when auto-subscribing is off', function () {
    MailingList::fake();
    config(['waitlist.mailing_list.auto_subscribe' => false]);

    $log = capturedLog();

    Waitlist::add('John Doe', 'john@example.com');

    expect($log)->toHaveLoggedOnce('waitlist.mailing_list.auto_subscribe');
});

/*
 * The one people come looking for: the entry exists, nothing failed, and it is
 * simply waiting for a click that may never come.
 */
test('it says why in the log while an entry waits to be verified', function () {
    MailingList::fake();
    config(['waitlist.verification.enabled' => true]);

    // Held back so the verification email cannot write a log line of its own.
    Notification::fake();

    $log = capturedLog();

    Waitlist::add('John Doe', 'john@example.com');

    expect($log)->toHaveLoggedOnce('verified');
});

/*
 * A verification event with verification switched off is a no-op by design —
 * the entry was synced when it was added — so it must not sync a second time,
 * and must not report itself as a skipped sync either.
 */
test('a verification event does nothing when verification is off', function () {
    MailingList::fake('fake-list');
    config(['waitlist.verification.enabled' => false]);
    Event::fake([WaitlistEntrySubscribed::class]);

    $entry = Waitlist::add('John Doe', 'john@example.com');

    Event::assertDispatchedTimes(WaitlistEntrySubscribed::class, 1);

    event(new WaitlistEntryVerified($entry));

    Event::assertDispatchedTimes(WaitlistEntrySubscribed::class, 1);
});

test('adding an entry subscribes it to the mailing list', function () {
    $fake = MailingList::fake('fake-list');

    $entry = Waitlist::add('John Doe', 'john@example.com');

    expect($fake->hasSubscriber('john@example.com', 'fake-list'))->toBeTrue()
        ->and($entry->fresh()->mailing_list_driver)->toBe('array')
        ->and($entry->fresh()->mailing_list_subscriber_id)->toBe(md5('john@example.com'))
        ->and($entry->fresh()->isSubscribedToMailingList())->toBeTrue();
});

test('a subscribed entry fires an event', function () {
    MailingList::fake();
    Event::fake([WaitlistEntrySubscribed::class]);

    $entry = Waitlist::add('John Doe', 'john@example.com');

    Event::assertDispatched(
        WaitlistEntrySubscribed::class,
        fn (WaitlistEntrySubscribed $event) => $event->entry->is($entry)
            && $event->driver === 'array'
            && $event->subscriber instanceof Subscriber
    );
});

test('with verification enabled the entry only syncs once verified', function () {
    $fake = MailingList::fake();
    config(['waitlist.verification.enabled' => true]);

    $entry = Waitlist::add('John Doe', 'john@example.com');

    expect($fake->hasSubscriber('john@example.com'))->toBeFalse();

    Waitlist::verify($entry->fresh()->verification_token);

    expect($fake->hasSubscriber('john@example.com'))->toBeTrue();
});

test('auto subscribing can be turned off', function () {
    $fake = MailingList::fake();
    config(['waitlist.mailing_list.auto_subscribe' => false]);

    Waitlist::add('John Doe', 'john@example.com');

    expect($fake->subscribers())->toBeEmpty();
});

test('syncing is queued by default', function () {
    Queue::fake();
    MailingList::fake();
    config(['waitlist.mailing_list.queue.enabled' => true]);

    Waitlist::add('John Doe', 'john@example.com');

    Queue::assertPushed(SyncEntryToMailingList::class);
});

// Per waitlist lists

test('each waitlist can point at its own list', function () {
    $fake = MailingList::fake();

    Waitlist::create('Beta', 'beta');
    Waitlist::create('Launch', 'launch');

    Waitlist::for('beta')->connectMailingList('beta-audience');
    Waitlist::for('launch')->connectMailingList('launch-audience');

    Waitlist::for('beta')->add('John Doe', 'john@example.com');
    Waitlist::for('launch')->add('Jane Doe', 'jane@example.com');

    expect($fake->hasSubscriber('john@example.com', 'beta-audience'))->toBeTrue()
        ->and($fake->hasSubscriber('jane@example.com', 'launch-audience'))->toBeTrue()
        ->and($fake->hasSubscriber('jane@example.com', 'beta-audience'))->toBeFalse();
});

test('a waitlist can override the driver', function () {
    MailingList::fake();

    $beta = Waitlist::create('Beta', 'beta');
    Waitlist::for('beta')->connectMailingList('beta-audience', 'mailchimp');

    expect(MailingList::driverNameFor($beta->fresh()))->toBe('mailchimp')
        ->and(MailingList::listIdFor($beta->fresh()))->toBe('beta-audience');
});

test('a waitlist falls back to the configured default list', function () {
    MailingList::fake('default-list');

    $beta = Waitlist::create('Beta', 'beta');

    expect(MailingList::listIdFor($beta))->toBe('default-list');
});

test('a waitlist can be disconnected', function () {
    MailingList::fake();

    Waitlist::create('Beta', 'beta');
    Waitlist::for('beta')->connectMailingList('beta-audience');
    $beta = Waitlist::for('beta')->disconnectMailingList();

    expect($beta->fresh()->mailingListId())->toBeNull();
});

test('a missing list id reports a failure instead of retrying forever', function () {
    MailingList::fake();
    config(['waitlist.mailing_list.drivers.array.list_id' => null]);
    Event::fake([MailingListSyncFailed::class]);

    $entry = Waitlist::add('John Doe', 'john@example.com');

    expect($entry->fresh()->isSubscribedToMailingList())->toBeFalse();

    Event::assertDispatched(
        MailingListSyncFailed::class,
        fn (MailingListSyncFailed $event) => $event->exception instanceof MailingListException
    );
});

// Manual syncing

test('an entry can be subscribed and unsubscribed by hand', function () {
    $fake = MailingList::fake();
    config(['waitlist.mailing_list.auto_subscribe' => false]);

    $entry = Waitlist::add('John Doe', 'john@example.com');

    Waitlist::subscribeToMailingList($entry);
    expect($fake->hasSubscriber('john@example.com'))->toBeTrue();

    Event::fake([WaitlistEntryUnsubscribed::class]);
    Waitlist::unsubscribeFromMailingList($entry);

    expect($fake->hasSubscriber('john@example.com'))->toBeFalse()
        ->and($entry->fresh()->isSubscribedToMailingList())->toBeFalse();

    Event::assertDispatched(WaitlistEntryUnsubscribed::class);
});

test('existing entries can be backfilled', function () {
    $fake = MailingList::fake();
    config(['waitlist.mailing_list.auto_subscribe' => false]);

    Waitlist::add('User 1', 'user1@example.com');
    Waitlist::add('User 2', 'user2@example.com');

    expect(Waitlist::syncMailingList())->toBe(2)
        ->and($fake->subscribers())->toHaveCount(2);

    // Already synced entries are skipped on the next run.
    expect(Waitlist::syncMailingList())->toBe(0)
        ->and(Waitlist::syncMailingList(force: true))->toBe(2);
});

test('backfilling skips unverified entries when verification is enabled', function () {
    $fake = MailingList::fake();
    config([
        'waitlist.mailing_list.auto_subscribe' => false,
        'waitlist.verification.enabled' => true,
    ]);

    $verified = Waitlist::add('User 1', 'user1@example.com');
    Waitlist::add('User 2', 'user2@example.com');

    Waitlist::verify($verified->fresh()->verification_token);

    expect(Waitlist::syncMailingList())->toBe(1)
        ->and($fake->hasSubscriber('user1@example.com'))->toBeTrue()
        ->and($fake->hasSubscriber('user2@example.com'))->toBeFalse();
});

test('the sync command queues entries for every waitlist', function () {
    $fake = MailingList::fake();
    config(['waitlist.mailing_list.auto_subscribe' => false]);

    Waitlist::create('Beta', 'beta');
    Waitlist::for('beta')->connectMailingList('beta-audience');
    Waitlist::for('beta')->add('John Doe', 'john@example.com');

    $this->artisan('waitlist:sync-mailing-list', ['--all' => true])
        ->assertSuccessful();

    expect($fake->hasSubscriber('john@example.com', 'beta-audience'))->toBeTrue();
});

test('the sync command stops when the integration is disabled', function () {
    config(['waitlist.mailing_list.enabled' => false]);

    $this->artisan('waitlist:sync-mailing-list')->assertFailed();
});

// Drivers

test('the mailchimp driver upserts a member on the audience', function () {
    useDriver('mailchimp', [
        'key' => 'secret-us14',
        'list_id' => 'audience123',
    ]);

    Http::fake([
        '*' => Http::response([
            'id' => 'subscriber-hash',
            'email_address' => 'john@example.com',
            'status' => 'subscribed',
        ]),
    ]);

    $entry = Waitlist::add('John Doe', 'john@example.com');

    Http::assertSent(function ($request) {
        return $request->method() === 'PUT'
            && $request->url() === 'https://us14.api.mailchimp.com/3.0/lists/audience123/members/'.md5('john@example.com')
            && $request['email_address'] === 'john@example.com'
            && $request['status_if_new'] === 'subscribed'
            && $request['merge_fields'] === ['FNAME' => 'John', 'LNAME' => 'Doe'];
    });

    expect($entry->fresh()->mailing_list_subscriber_id)->toBe('subscriber-hash');
});

test('the mailchimp driver sends pending members when double opt-in is on', function () {
    useDriver('mailchimp', ['key' => 'secret-us14', 'list_id' => 'audience123']);
    config(['waitlist.mailing_list.double_optin' => true]);

    Http::fake(['*' => Http::response(['id' => 'hash', 'email_address' => 'john@example.com'])]);

    Waitlist::add('John Doe', 'john@example.com');

    Http::assertSent(fn ($request) => $request['status_if_new'] === 'pending');
});

test('the mailchimp driver applies configured tags', function () {
    useDriver('mailchimp', ['key' => 'secret-us14', 'list_id' => 'audience123']);
    config(['waitlist.mailing_list.tags' => ['waitlist']]);

    Http::fake(['*' => Http::response(['id' => 'hash', 'email_address' => 'john@example.com'])]);

    Waitlist::add('John Doe', 'john@example.com');

    Http::assertSent(function ($request) {
        return str_ends_with($request->url(), '/tags')
            && $request['tags'] === [['name' => 'waitlist', 'status' => 'active']];
    });
});

test('the mailchimp driver derives the data centre from the api key', function () {
    useDriver('mailchimp', ['key' => 'secret-us20', 'list_id' => 'audience123']);

    Http::fake(['*' => Http::response(['id' => 'hash', 'email_address' => 'john@example.com'])]);

    Waitlist::add('John Doe', 'john@example.com');

    Http::assertSent(fn ($request) => str_starts_with($request->url(), 'https://us20.api.mailchimp.com/3.0/'));
});

test('a failing mailchimp request raises a mailing list exception', function () {
    useDriver('mailchimp', ['key' => 'secret-us14', 'list_id' => 'audience123']);

    Http::fake(['*' => Http::response(['detail' => 'Invalid resource'], 400)]);

    expect(fn () => Waitlist::add('John Doe', 'john@example.com'))
        ->toThrow(MailingListException::class, 'Invalid resource');
});

test('the kit driver creates the subscriber and adds them to the form', function () {
    useDriver('kit', ['key' => 'kit-key', 'list_id' => 'form123']);

    Http::fake([
        'api.kit.com/v4/subscribers' => Http::response([
            'subscriber' => ['id' => 42, 'email_address' => 'john@example.com', 'state' => 'active'],
        ]),
        'api.kit.com/v4/forms/*' => Http::response(['subscriber' => ['id' => 42]]),
    ]);

    $entry = Waitlist::add('John Doe', 'john@example.com');

    Http::assertSent(function ($request) {
        return $request->url() === 'https://api.kit.com/v4/subscribers'
            && $request->hasHeader('X-Kit-Api-Key', 'kit-key')
            && $request['email_address'] === 'john@example.com'
            && $request['first_name'] === 'John';
    });

    Http::assertSent(fn ($request) => $request->url() === 'https://api.kit.com/v4/forms/form123/subscribers');

    expect($entry->fresh()->mailing_list_subscriber_id)->toBe('42');
});

test('the kit driver can treat the list id as a tag', function () {
    useDriver('kit', ['key' => 'kit-key', 'list_id' => 'tag99', 'list_type' => 'tag']);

    Http::fake(['*' => Http::response(['subscriber' => ['id' => 7, 'email_address' => 'john@example.com']])]);

    Waitlist::add('John Doe', 'john@example.com');

    Http::assertSent(fn ($request) => $request->url() === 'https://api.kit.com/v4/tags/tag99/subscribers');
});

test('the audienceful driver upserts the contact and grants publication consent', function () {
    useDriver('audienceful', ['key' => 'af-key', 'list_id' => 'pub123']);

    Http::fake([
        'api.audienceful.com/v2/people' => Http::response([
            'id' => 'jQKdwqp3YR',
            'email' => 'john@example.com',
            'status' => 'active',
        ]),
        'api.audienceful.com/v2/people/publications' => Http::response(['data' => []]),
    ]);

    $entry = Waitlist::add('John Doe', 'john@example.com');

    Http::assertSent(function ($request) {
        return $request->url() === 'https://api.audienceful.com/v2/people'
            && $request->method() === 'POST'
            && $request->hasHeader('X-Api-Key', 'af-key')
            && $request['email'] === 'john@example.com'
            && $request['extra_data'] === ['name' => 'John Doe']
            && ! isset($request['double_opt_in']);
    });

    Http::assertSent(function ($request) {
        return $request->url() === 'https://api.audienceful.com/v2/people/publications'
            && $request['email'] === 'john@example.com'
            && $request['publications'] === ['pub123' => true];
    });

    expect($entry->fresh()->mailing_list_subscriber_id)->toBe('jQKdwqp3YR');
});

test('the audienceful driver asks for double opt-in when it is on', function () {
    useDriver('audienceful', ['key' => 'af-key', 'list_id' => 'pub123']);
    config(['waitlist.mailing_list.double_optin' => true]);

    Http::fake(['*' => Http::response(['id' => 'p1', 'email' => 'john@example.com'])]);

    Waitlist::add('John Doe', 'john@example.com');

    Http::assertSent(fn ($request) => $request->url() === 'https://api.audienceful.com/v2/people'
        && $request['double_opt_in'] === 'required');
});

test('the audienceful driver can treat the list id as a tag', function () {
    useDriver('audienceful', ['key' => 'af-key', 'list_id' => 'beta', 'list_type' => 'tag']);
    config(['waitlist.mailing_list.tags' => ['waitlist']]);

    Http::fake(['*' => Http::response(['id' => 'p1', 'email' => 'john@example.com'])]);

    Waitlist::add('John Doe', 'john@example.com');

    Http::assertSent(fn ($request) => $request['tags'] === ['beta', 'waitlist']);
    Http::assertNotSent(fn ($request) => str_ends_with($request->url(), '/people/publications'));
});

test('the audienceful driver withdraws consent for the publication', function () {
    useDriver('audienceful', ['key' => 'af-key', 'list_id' => 'pub123']);
    config(['waitlist.mailing_list.auto_subscribe' => false]);

    Http::fake(['*' => Http::response(['data' => []])]);

    $entry = Waitlist::add('John Doe', 'john@example.com');
    Waitlist::unsubscribeFromMailingList($entry);

    Http::assertSent(function ($request) {
        return $request->url() === 'https://api.audienceful.com/v2/people/publications'
            && $request['publications'] === ['pub123' => false];
    });
});

test('the audienceful driver unsubscribes workspace wide when lists are tags', function () {
    useDriver('audienceful', ['key' => 'af-key', 'list_id' => 'beta', 'list_type' => 'tag']);
    config(['waitlist.mailing_list.auto_subscribe' => false]);

    Http::fake(['*' => Http::response([])]);

    $entry = Waitlist::add('John Doe', 'john@example.com');
    Waitlist::unsubscribeFromMailingList($entry);

    Http::assertSent(fn ($request) => $request->url() === 'https://api.audienceful.com/v2/people/unsubscribe'
        && $request['email'] === 'john@example.com');
});

test('unsubscribing an unknown audienceful contact is not an error', function () {
    useDriver('audienceful', ['key' => 'af-key', 'list_id' => 'pub123']);
    config(['waitlist.mailing_list.auto_subscribe' => false]);

    Http::fake(['*' => Http::response(['error' => ['message' => 'Not found.']], 404)]);

    $entry = Waitlist::add('John Doe', 'john@example.com');

    expect(fn () => Waitlist::unsubscribeFromMailingList($entry))->not->toThrow(MailingListException::class);
});

test('the audienceful driver finds a contact by email', function () {
    useDriver('audienceful', ['key' => 'af-key', 'list_id' => 'pub123']);

    Http::fake([
        'api.audienceful.com/v2/people?email=jane*' => Http::response(['data' => []]),
        'api.audienceful.com/v2/people*' => Http::response([
            'data' => [['id' => 'p1', 'email' => 'john@example.com', 'status' => 'active']],
            'has_more' => false,
            'next_cursor' => null,
        ]),
    ]);

    $driver = MailingList::driver('audienceful');

    expect($driver->find('john@example.com', 'pub123'))
        ->toBeInstanceOf(Subscriber::class)
        ->id->toBe('p1')
        ->status->toBe('active')
        ->and($driver->find('jane@example.com', 'pub123'))->toBeNull();
});

test('a failing audienceful request raises a mailing list exception', function () {
    useDriver('audienceful', ['key' => 'af-key', 'list_id' => 'pub123']);

    Http::fake(['*' => Http::response([
        'error' => ['type' => 'invalid_request_error', 'message' => 'Enter a valid email address.'],
    ], 400)]);

    expect(fn () => Waitlist::add('John Doe', 'john@example.com'))
        ->toThrow(MailingListException::class, 'Enter a valid email address.');
});

test('the log driver never touches the network', function () {
    useDriver('log', ['list_id' => 'log']);

    Http::fake();

    $entry = Waitlist::add('John Doe', 'john@example.com');

    Http::assertNothingSent();

    expect($entry->fresh()->mailing_list_driver)->toBe('log');
});

test('custom drivers can be registered', function () {
    config([
        'waitlist.mailing_list.enabled' => true,
        'waitlist.mailing_list.default' => 'custom',
        'waitlist.mailing_list.queue.enabled' => false,
        'waitlist.mailing_list.drivers.custom' => ['list_id' => 'custom-list'],
    ]);

    MailingList::extend('custom', fn (array $config) => new class implements MailingListDriver
    {
        public function name(): string
        {
            return 'custom';
        }

        public function subscribe(WaitlistEntry $entry, string $listId, array $options = []): Subscriber
        {
            return new Subscriber(id: 'custom-1', email: $entry->email);
        }

        public function unsubscribe(WaitlistEntry $entry, string $listId): void {}

        public function tag(WaitlistEntry $entry, string $listId, array $tags): void {}

        public function find(string $email, string $listId): ?Subscriber
        {
            return null;
        }
    });

    $entry = Waitlist::add('John Doe', 'john@example.com');

    expect($entry->fresh()->mailing_list_driver)->toBe('custom')
        ->and($entry->fresh()->mailing_list_subscriber_id)->toBe('custom-1');
});

test('an unknown driver is rejected', function () {
    config(['waitlist.mailing_list.default' => 'nope']);

    expect(fn () => MailingList::driver())->toThrow(MailingListException::class, 'not supported');
});

test('the fake driver records the tags a real driver would apply', function () {
    $fake = MailingList::fake();
    config(['waitlist.mailing_list.tags' => ['beta']]);

    $entry = Waitlist::add('John Doe', 'john@example.com');
    MailingList::tagEntry($entry, ['invited']);

    expect($fake)->toBeInstanceOf(ArrayDriver::class)
        ->and($fake->tagsFor('john@example.com', 'fake-list'))->toBe(['beta', 'invited']);
});

/**
 * Point the integration at a real driver, running syncs inline.
 *
 * @param  array<string, mixed>  $config
 */
function useDriver(string $driver, array $config): void
{
    config([
        'waitlist.mailing_list.enabled' => true,
        'waitlist.mailing_list.default' => $driver,
        'waitlist.mailing_list.queue.enabled' => false,
        "waitlist.mailing_list.drivers.{$driver}" => $config,
    ]);
}
