<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use OffloadProject\Waitlist\Exceptions\MailingListException;
use OffloadProject\Waitlist\Facades\MailingList;
use OffloadProject\Waitlist\Facades\Waitlist;

const CC_LIST = 'd13d60d0-f256-11e8-b47d-fa163e56c9b0';

beforeEach(function (): void {
    config([
        'waitlist.mailing_list.enabled' => true,
        'waitlist.mailing_list.default' => 'constant_contact',
        'waitlist.mailing_list.queue.enabled' => false,
        'waitlist.mailing_list.drivers.constant_contact.client_id' => 'client-id',
        'waitlist.mailing_list.drivers.constant_contact.client_secret' => 'client-secret',
        'waitlist.mailing_list.drivers.constant_contact.refresh_token' => 'seed-refresh-token',
        'waitlist.mailing_list.drivers.constant_contact.list_id' => CC_LIST,
    ]);
});

/** The token exchange, which every other call depends on. */
function ccToken(array $overrides = []): array
{
    return array_merge([
        'access_token' => 'access-1',
        'refresh_token' => 'refresh-2',
        'expires_in' => 86400,
    ], $overrides);
}

test('it signs a new entry up and puts them on the list', function () {
    Http::fake([
        '*/oauth2/*' => Http::response(ccToken()),
        '*/contacts/sign_up_form' => Http::response(['contact_id' => 'contact-1', 'action' => 'created'], 201),
    ]);

    $entry = Waitlist::add('John Doe', 'john@example.com');

    Http::assertSent(function ($request): bool {
        if (! str_contains($request->url(), 'sign_up_form')) {
            return false;
        }

        return $request['email_address'] === 'john@example.com'
            && $request['first_name'] === 'John'
            && $request['list_memberships'] === [CC_LIST];
    });

    expect($entry->fresh()->mailing_list_driver)->toBe('constant_contact')
        ->and($entry->fresh()->mailing_list_subscriber_id)->toBe('contact-1');
});

/*
 * The refresh token is single use: each exchange invalidates the one sent and
 * issues a replacement. Losing the replacement ends the connection, so it is
 * written back before the access token is ever used.
 */
test('it stores the rotated refresh token', function () {
    Http::fake([
        '*/oauth2/*' => Http::response(ccToken(['refresh_token' => 'refresh-rotated'])),
        '*/contacts/sign_up_form' => Http::response(['contact_id' => 'c1', 'action' => 'created'], 201),
    ]);

    Waitlist::add('John Doe', 'john@example.com');

    expect(Cache::get('waitlist.constant_contact.refresh_token'))->toBe('refresh-rotated');
});

test('it reuses a cached access token rather than exchanging every time', function () {
    Http::fake([
        '*/oauth2/*' => Http::response(ccToken()),
        '*/contacts/sign_up_form' => Http::response(['contact_id' => 'c1', 'action' => 'created'], 201),
    ]);

    Waitlist::add('One', 'one@example.com');
    MailingList::forget();
    Waitlist::add('Two', 'two@example.com');

    $exchanges = collect(Http::recorded())
        ->filter(fn (array $pair): bool => str_contains($pair[0]->url(), 'oauth2'))
        ->count();

    expect($exchanges)->toBe(1);
});

/*
 * A refused refresh token is not a transient failure — nobody can fix it
 * without authorising the account again in a browser — so the message has to
 * say that rather than reading as a network blip.
 */
test('it says so plainly when the refresh token is refused', function () {
    Http::fake([
        '*/oauth2/*' => Http::response(['error' => 'invalid_grant'], 400),
    ]);

    expect(fn () => Waitlist::add('John Doe', 'john@example.com'))
        ->toThrow(MailingListException::class, 'authorised again');
});

test('it needs a client id and secret', function () {
    config(['waitlist.mailing_list.drivers.constant_contact.client_id' => null]);

    expect(fn () => MailingList::driver('constant_contact'))
        ->toThrow(MailingListException::class, 'client_id');
});

/*
 * Constant Contact addresses custom fields by id and rejects the whole contact
 * over one it does not know, so an unmatched attribute is dropped instead.
 */
test('it maps attributes onto custom field ids and drops the rest', function () {
    config(['waitlist.mailing_list.attributes' => fn () => ['Persona' => 'System Hopper', 'Nonesuch' => 'x']]);

    Http::fake([
        '*/oauth2/*' => Http::response(ccToken()),
        '*/contact_custom_fields*' => Http::response(['custom_fields' => [
            ['custom_field_id' => 'field-1', 'label' => 'Persona', 'name' => 'persona'],
        ]]),
        '*/contacts/sign_up_form' => Http::response(['contact_id' => 'c1', 'action' => 'created'], 201),
    ]);

    Waitlist::add('John Doe', 'john@example.com');

    Http::assertSent(function ($request): bool {
        if (! str_contains($request->url(), 'sign_up_form')) {
            return false;
        }

        return $request['custom_fields'] === [['custom_field_id' => 'field-1', 'value' => 'System Hopper']];
    });
});

test('it creates a tag it has not seen and applies it', function () {
    config(['waitlist.mailing_list.tags' => ['yhy-waitlist']]);

    Http::fake([
        '*/oauth2/*' => Http::response(ccToken()),
        '*/contacts/sign_up_form' => Http::response(['contact_id' => 'contact-1', 'action' => 'created'], 201),
        '*/contacts?*' => Http::response(['contacts' => [['contact_id' => 'contact-1', 'list_memberships' => [CC_LIST]]]]),
        '*/contact_tags?*' => Http::response(['tags' => []]),
        '*/contact_tags' => Http::response(['tag_id' => 'tag-1'], 201),
        '*/activities/contacts_taggings_add' => Http::response(['activity_id' => 'a1'], 201),
    ]);

    Waitlist::add('John Doe', 'john@example.com');

    Http::assertSent(fn ($request): bool => str_contains($request->url(), 'contacts_taggings_add')
        && $request['tag_id'] === 'tag-1'
        && $request['source']['contact_ids'] === ['contact-1']);
});

/*
 * Leaving one list must not take the others with it, so the contact is updated
 * with the remaining memberships rather than deleted.
 */
test('unsubscribing removes only this list', function () {
    Http::fake([
        '*/oauth2/*' => Http::response(ccToken()),
        '*/contacts/sign_up_form' => Http::response(['contact_id' => 'contact-1', 'action' => 'created'], 201),
        '*/contacts?*' => Http::response(['contacts' => [[
            'contact_id' => 'contact-1',
            'list_memberships' => [CC_LIST, 'other-list'],
        ]]]),
        '*/contacts/contact-1' => Http::response(['contact_id' => 'contact-1'], 200),
    ]);

    $entry = Waitlist::add('John Doe', 'john@example.com');

    Waitlist::unsubscribeFromMailingList($entry);

    Http::assertSent(fn ($request): bool => $request->method() === 'PUT'
        && str_contains($request->url(), '/contacts/contact-1')
        && $request['list_memberships'] === ['other-list']);
});
