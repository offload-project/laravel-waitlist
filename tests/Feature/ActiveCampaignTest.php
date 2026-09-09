<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use OffloadProject\Waitlist\Exceptions\MailingListException;
use OffloadProject\Waitlist\Facades\MailingList;
use OffloadProject\Waitlist\Facades\Waitlist;

const AC_URL = 'https://acme.api-us1.com';

beforeEach(function (): void {
    config([
        'waitlist.mailing_list.enabled' => true,
        'waitlist.mailing_list.default' => 'activecampaign',
        'waitlist.mailing_list.queue.enabled' => false,
        'waitlist.mailing_list.drivers.activecampaign.key' => 'ac-key',
        'waitlist.mailing_list.drivers.activecampaign.url' => AC_URL,
        'waitlist.mailing_list.drivers.activecampaign.list_id' => '7',
    ]);
});

function acAccepts(array $overrides = []): void
{
    Http::fake(array_merge([
        '*/api/3/contact/sync' => Http::response(['contact' => ['id' => '115', 'email' => 'john@example.com']], 201),
        '*/api/3/contactLists' => Http::response(['contactList' => ['id' => '9']], 201),
        '*/api/3/contacts*' => Http::response(['contacts' => [['id' => '115', 'status' => '1']]]),
        '*/api/3/fields*' => Http::response(['fields' => [
            ['id' => '3', 'title' => 'Persona', 'perstag' => 'PERSONA'],
        ]]),
        '*/api/3/tags*' => Http::response(['tags' => []]),
        '*/api/3/contactTags' => Http::response(['contactTag' => ['id' => '55']], 201),
    ], $overrides));
}

test('it upserts the contact and puts them on the list', function () {
    acAccepts();

    $entry = Waitlist::add('John Doe', 'john@example.com');

    Http::assertSent(fn ($request): bool => str_contains($request->url(), '/contact/sync')
        && $request['contact']['email'] === 'john@example.com'
        && $request['contact']['firstName'] === 'John');

    Http::assertSent(fn ($request): bool => str_contains($request->url(), '/contactLists')
        && $request['contactList']['list'] === '7'
        && $request['contactList']['contact'] === '115'
        && $request['contactList']['status'] === 1);

    expect($entry->fresh()->mailing_list_driver)->toBe('activecampaign')
        ->and($entry->fresh()->mailing_list_subscriber_id)->toBe('115');
});

test('it authenticates with the Api-Token header', function () {
    acAccepts();

    Waitlist::add('John Doe', 'john@example.com');

    Http::assertSent(fn ($request): bool => $request->hasHeader('Api-Token', 'ac-key'));
});

test('it appends the api version to the account url', function () {
    acAccepts();

    Waitlist::add('John Doe', 'john@example.com');

    Http::assertSent(fn ($request): bool => str_starts_with($request->url(), AC_URL.'/api/3/'));
});

/*
 * ActiveCampaign refuses a duplicate tag and treats tags case-insensitively, so
 * a blind create works once and fails on every sign-up after it.
 */
test('it reuses an existing tag rather than recreating it', function () {
    config(['waitlist.mailing_list.tags' => ['yhy-waitlist']]);

    acAccepts(['*/api/3/tags*' => Http::response(['tags' => [
        ['id' => '20', 'tag' => 'YHY-Waitlist'],
    ]])]);

    Waitlist::add('John Doe', 'john@example.com');

    Http::assertNotSent(fn ($request): bool => $request->method() === 'POST'
        && str_ends_with($request->url(), '/api/3/tags'));

    Http::assertSent(fn ($request): bool => str_contains($request->url(), '/contactTags')
        && $request['contactTag']['tag'] === '20'
        && $request['contactTag']['contact'] === '115');
});

test('it creates a tag it has not seen', function () {
    config(['waitlist.mailing_list.tags' => ['yhy-waitlist']]);

    acAccepts(['*/api/3/tags' => Http::response(['tag' => ['id' => '31']], 201)]);

    Waitlist::add('John Doe', 'john@example.com');

    Http::assertSent(fn ($request): bool => $request->method() === 'POST'
        && str_ends_with($request->url(), '/api/3/tags')
        && $request['tag']['tag'] === 'yhy-waitlist'
        && $request['tag']['tagType'] === 'contact');
});

/*
 * Custom fields are addressed by id. An attribute the account has no field for
 * is dropped rather than sent — losing an annotation beats losing the sign-up.
 */
test('it maps attributes onto field ids and drops the rest', function () {
    config(['waitlist.mailing_list.attributes' => fn () => ['Persona' => 'System Hopper', 'Nonesuch' => 'x']]);

    acAccepts();

    Waitlist::add('John Doe', 'john@example.com');

    Http::assertSent(function ($request): bool {
        if (! str_contains($request->url(), '/contact/sync')) {
            return false;
        }

        return $request['contact']['fieldValues'] === [['field' => '3', 'value' => 'System Hopper']];
    });
});

/*
 * Status 2 rather than a delete: the unsubscribe is a fact about this list, and
 * deleting the contact would take every other list with it.
 */
test('unsubscribing sets the list status rather than deleting the contact', function () {
    acAccepts();

    $entry = Waitlist::add('John Doe', 'john@example.com');

    Waitlist::unsubscribeFromMailingList($entry);

    Http::assertSent(fn ($request): bool => str_contains($request->url(), '/contactLists')
        && $request['contactList']['status'] === 2);

    Http::assertNotSent(fn ($request): bool => $request->method() === 'DELETE');
});

test('it needs a key and an account url', function () {
    config(['waitlist.mailing_list.drivers.activecampaign.url' => null]);

    expect(fn () => MailingList::driver('activecampaign'))
        ->toThrow(MailingListException::class, 'url');
});

test('it carries the reason into the failure', function () {
    acAccepts(['*/api/3/contact/sync' => Http::response(['errors' => [['title' => 'Email address is not valid.']]], 422)]);

    expect(fn () => Waitlist::add('John Doe', 'john@example.com'))
        ->toThrow(MailingListException::class, 'Email address is not valid.');
});
