<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use OffloadProject\Waitlist\Exceptions\MailingListException;
use OffloadProject\Waitlist\Facades\MailingList;
use OffloadProject\Waitlist\Facades\Waitlist;

beforeEach(function (): void {
    config([
        'waitlist.mailing_list.enabled' => true,
        'waitlist.mailing_list.default' => 'gohighlevel',
        'waitlist.mailing_list.queue.enabled' => false,
        'waitlist.mailing_list.drivers.gohighlevel.key' => 'ghl-token',
        'waitlist.mailing_list.drivers.gohighlevel.list_id' => 'loc-1',
    ]);
});

function ghlAccepts(array $overrides = []): void
{
    Http::fake(array_merge([
        '*/contacts/upsert' => Http::response(['new' => true, 'contact' => ['id' => 'c-115', 'email' => 'john@example.com']]),
        '*/contacts/search/duplicate*' => Http::response(['contact' => ['id' => 'c-115']]),
        '*/contacts/c-115/tags' => Http::response(['tags' => ['yhy-waitlist']], 201),
        '*/contacts/c-115' => Http::response(['contact' => ['id' => 'c-115']]),
        '*/locations/loc-1/customFields*' => Http::response(['customFields' => [
            ['id' => 'f-3', 'name' => 'Persona', 'fieldKey' => 'contact.persona'],
        ]]),
    ], $overrides));
}

test('it upserts the contact into the location', function () {
    ghlAccepts();

    $entry = Waitlist::add('John Doe', 'john@example.com');

    Http::assertSent(fn ($request): bool => str_ends_with($request->url(), '/contacts/upsert')
        && $request['locationId'] === 'loc-1'
        && $request['email'] === 'john@example.com'
        && $request['firstName'] === 'John'
        && $request['lastName'] === 'Doe'
        && $request['dndSettings'] === ['email' => ['status' => 'inactive']]);

    expect($entry->fresh()->mailing_list_driver)->toBe('gohighlevel')
        ->and($entry->fresh()->mailing_list_subscriber_id)->toBe('c-115');
});

test('it authenticates with a bearer token and pins the api version', function () {
    ghlAccepts();

    Waitlist::add('John Doe', 'john@example.com');

    Http::assertSent(fn ($request): bool => $request->hasHeader('Authorization', 'Bearer ghl-token')
        && $request->hasHeader('Version', 'v3'));
});

/*
 * Tags on an upsert replace every tag the contact has, so they go through the
 * add-tags endpoint instead — anything else the account tagged them with stays.
 */
test('it adds tags rather than sending them with the upsert', function () {
    config(['waitlist.mailing_list.tags' => ['yhy-waitlist']]);

    ghlAccepts();

    Waitlist::add('John Doe', 'john@example.com');

    Http::assertSent(fn ($request): bool => str_ends_with($request->url(), '/contacts/c-115/tags')
        && $request->method() === 'POST'
        && $request['tags'] === ['yhy-waitlist']);

    Http::assertNotSent(fn ($request): bool => str_ends_with($request->url(), '/contacts/upsert')
        && isset($request['tags']));
});

/*
 * Custom fields are addressed by id. An attribute the location has no field for
 * is dropped rather than sent — losing an annotation beats losing the sign-up.
 */
test('it maps attributes onto field ids and drops the rest', function () {
    config(['waitlist.mailing_list.attributes' => fn () => [
        'Persona' => 'System Hopper',
        'Nonesuch' => 'x',
    ]]);

    ghlAccepts();

    Waitlist::add('John Doe', 'john@example.com');

    Http::assertSent(fn ($request): bool => str_ends_with($request->url(), '/contacts/upsert')
        && $request['customFields'] === [['id' => 'f-3', 'fieldValue' => 'System Hopper']]);
});

test('it matches a field by its key, with or without the contact prefix', function () {
    config(['waitlist.mailing_list.attributes' => fn () => ['persona' => 'System Hopper']]);

    ghlAccepts();

    Waitlist::add('John Doe', 'john@example.com');

    Http::assertSent(fn ($request): bool => str_ends_with($request->url(), '/contacts/upsert')
        && $request['customFields'] === [['id' => 'f-3', 'fieldValue' => 'System Hopper']]);
});

/*
 * The location is the list, so unsubscribing is email DND: the contact, its
 * tags and its other channels are left as they were.
 */
test('unsubscribing turns on email dnd rather than deleting the contact', function () {
    ghlAccepts();

    $entry = Waitlist::add('John Doe', 'john@example.com');

    Waitlist::unsubscribeFromMailingList($entry);

    Http::assertSent(fn ($request): bool => $request->method() === 'PUT'
        && str_ends_with($request->url(), '/contacts/c-115')
        && $request['dndSettings'] === ['email' => ['status' => 'active']]);

    Http::assertNotSent(fn ($request): bool => $request->method() === 'DELETE');
});

test('unsubscribing somebody who is not a contact does nothing', function () {
    ghlAccepts(['*/contacts/search/duplicate*' => Http::response(['contact' => null])]);

    $entry = Waitlist::add('John Doe', 'john@example.com');

    Waitlist::unsubscribeFromMailingList($entry);

    Http::assertNotSent(fn ($request): bool => $request->method() === 'PUT');
});

test('it reads email dnd as unsubscribed', function () {
    ghlAccepts(['*/contacts/search/duplicate*' => Http::response(['contact' => [
        'id' => 'c-115',
        'dndSettings' => ['email' => ['status' => 'active']],
    ]])]);

    expect(MailingList::driver('gohighlevel')->find('john@example.com', 'loc-1')?->status)
        ->toBe('unsubscribed');
});

test('it needs a key', function () {
    config(['waitlist.mailing_list.drivers.gohighlevel.key' => null]);

    expect(fn () => MailingList::driver('gohighlevel'))
        ->toThrow(MailingListException::class, 'key');
});

test('it carries the reason into the failure', function () {
    ghlAccepts(['*/contacts/upsert' => Http::response([
        'statusCode' => 422,
        'message' => ['email must be an email'],
        'error' => 'Unprocessable Entity',
    ], 422)]);

    expect(fn () => Waitlist::add('John Doe', 'john@example.com'))
        ->toThrow(MailingListException::class, 'email must be an email');
});
