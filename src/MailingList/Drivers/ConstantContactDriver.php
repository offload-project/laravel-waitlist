<?php

declare(strict_types=1);

namespace OffloadProject\Waitlist\MailingList\Drivers;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use OffloadProject\Waitlist\Contracts\MailingListDriver;
use OffloadProject\Waitlist\Exceptions\MailingListException;
use OffloadProject\Waitlist\MailingList\Concerns\ResolvesEntryAttributes;
use OffloadProject\Waitlist\MailingList\ConstantContact\AccessToken;
use OffloadProject\Waitlist\MailingList\Subscriber;
use OffloadProject\Waitlist\Models\WaitlistEntry;

/**
 * Constant Contact API V3.
 *
 * The list id is a contact list id (a UUID). Unlike Kit, which segments one
 * pool of subscribers by form or tag, Constant Contact has real lists and a
 * contact belongs to them directly.
 *
 * Two things about this provider shape the code here:
 *
 * - It is OAuth2 with no client-credentials flow, so there is no static key.
 *   `AccessToken` keeps a token in hand; see its notes on what that costs.
 * - Custom fields are addressed by id, not by name. Attributes are therefore
 *   resolved against the account's fields and anything unrecognised is dropped
 *   rather than sent, because the API rejects the whole contact over one
 *   unknown id.
 *
 * @see https://developer.constantcontact.com/api_guide/v3_technical_overview.html
 */
final class ConstantContactDriver implements MailingListDriver
{
    use ResolvesEntryAttributes;

    private const string BASE_URL = 'https://api.cc.email/v3';

    /** @var array<string, string>|null Field name (lowercased) to field id. */
    private ?array $customFieldIds = null;

    /** @var array<string, string> Tag name to tag id. */
    private array $tagIds = [];

    public function __construct(
        private readonly AccessToken $token,
        private readonly int $timeout = 10,
        private readonly int $retries = 2,
    ) {}

    public function name(): string
    {
        return 'constant_contact';
    }

    public function subscribe(WaitlistEntry $entry, string $listId, array $options = []): Subscriber
    {
        /*
         * `sign_up_form` rather than `POST /contacts`: it upserts on the email
         * address, and it records the contact's permission as having come from
         * a sign-up form, which is what actually happened. Creating the contact
         * outright would assert a consent basis the waitlist cannot vouch for.
         */
        $response = $this->request()->post('/contacts/sign_up_form', array_filter([
            'email_address' => $entry->email,
            'first_name' => $this->firstName($entry),
            'list_memberships' => [$listId],
            'custom_fields' => $this->customFields($entry, $options),
        ]));

        $this->throwUnlessSuccessful($response);

        $subscriber = new Subscriber(
            id: (string) $response->json('contact_id', ''),
            email: $entry->email,
            status: (string) $response->json('action', ''),
            raw: $response->json() ?? [],
        );

        $tags = $this->tags($options);

        if ($tags !== []) {
            $this->tag($entry, $listId, $tags);
        }

        return $subscriber;
    }

    /**
     * Removes the contact from this list rather than deleting them.
     *
     * Constant Contact has no per-list unsubscribe: setting the memberships to
     * everything but this list is how a contact leaves one list while staying
     * on the others. Deleting them would take the rest with it.
     */
    public function unsubscribe(WaitlistEntry $entry, string $listId): void
    {
        $contact = $this->contact($entry->email);

        if ($contact === null) {
            return;
        }

        $contactId = (string) ($contact['contact_id'] ?? '');

        /** @var list<string> $memberships */
        $memberships = array_values(array_filter(
            $contact['list_memberships'] ?? [],
            static fn (string $id): bool => $id !== $listId,
        ));

        $this->throwUnlessSuccessful(
            $this->request()->put("/contacts/{$contactId}", [
                'email_address' => ['address' => $entry->email],
                'list_memberships' => $memberships,
                // Required by the endpoint, and this is the only source we can
                // honestly claim for a waitlist sign-up.
                'update_source' => 'Contact',
            ])
        );
    }

    public function tag(WaitlistEntry $entry, string $listId, array $tags): void
    {
        $contact = $this->contact($entry->email);

        if ($contact === null) {
            return;
        }

        $contactId = (string) ($contact['contact_id'] ?? '');

        foreach ($tags as $tag) {
            $this->throwUnlessSuccessful(
                $this->request()->post('/activities/contacts_taggings_add', [
                    'source' => ['contact_ids' => [$contactId]],
                    'tag_id' => $this->tagId($tag),
                ])
            );
        }
    }

    public function find(string $email, string $listId): ?Subscriber
    {
        $contact = $this->contact($email);

        if ($contact === null) {
            return null;
        }

        return new Subscriber(
            id: (string) ($contact['contact_id'] ?? ''),
            email: $email,
            status: isset($contact['update_source']) ? (string) $contact['update_source'] : null,
            raw: $contact,
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    private function contact(string $email): ?array
    {
        $response = $this->request()->get('/contacts', [
            'email' => $email,
            'include' => 'list_memberships,custom_fields,taggings',
        ]);

        $this->throwUnlessSuccessful($response);

        /** @var array<int, array<string, mixed>> $contacts */
        $contacts = $response->json('contacts', []);

        return $contacts[0] ?? null;
    }

    /**
     * Attributes as Constant Contact wants them: a list of id/value pairs.
     *
     * An attribute with no matching field on the account is skipped. The
     * alternative is a rejected contact — the API fails the whole call over one
     * unknown id — and losing the sign-up is worse than losing the annotation.
     *
     * @param  array{tags?: array<array-key, string>, double_optin?: bool, attributes?: array<string, mixed>}  $options
     * @return list<array{custom_field_id: string, value: string}>
     */
    private function customFields(WaitlistEntry $entry, array $options): array
    {
        $attributes = $this->attributes($entry, $options);

        if ($attributes === []) {
            return [];
        }

        $fields = [];

        foreach ($attributes as $name => $value) {
            $id = $this->customFieldId((string) $name);

            if ($id !== null) {
                $fields[] = ['custom_field_id' => $id, 'value' => (string) $value];
            }
        }

        return $fields;
    }

    private function customFieldId(string $name): ?string
    {
        if ($this->customFieldIds === null) {
            $response = $this->request()->get('/contact_custom_fields', ['limit' => 100]);

            $this->throwUnlessSuccessful($response);

            $this->customFieldIds = [];

            /** @var array<int, array{custom_field_id?: string, label?: string, name?: string}> $fields */
            $fields = $response->json('custom_fields', []);

            foreach ($fields as $field) {
                $id = $field['custom_field_id'] ?? null;

                // Matched on either, because the label is what a person sees in
                // the UI and the name is what the API answers with.
                foreach ([$field['label'] ?? null, $field['name'] ?? null] as $key) {
                    if (is_string($key) && is_string($id)) {
                        $this->customFieldIds[mb_strtolower($key)] = $id;
                    }
                }
            }
        }

        return $this->customFieldIds[mb_strtolower($name)] ?? null;
    }

    /**
     * Resolve a tag name to its id, creating the tag when it does not exist.
     */
    private function tagId(string $tag): string
    {
        if (isset($this->tagIds[$tag])) {
            return $this->tagIds[$tag];
        }

        $response = $this->request()->get('/contact_tags', ['limit' => 500]);

        $this->throwUnlessSuccessful($response);

        /** @var array<int, array{tag_id?: string, name?: string}> $existing */
        $existing = $response->json('tags', []);

        foreach ($existing as $candidate) {
            if (isset($candidate['name'], $candidate['tag_id']) && $candidate['name'] === $tag) {
                return $this->tagIds[$tag] = (string) $candidate['tag_id'];
            }
        }

        $created = $this->request()->post('/contact_tags', ['name' => $tag]);

        $this->throwUnlessSuccessful($created);

        return $this->tagIds[$tag] = (string) $created->json('tag_id');
    }

    private function request(): PendingRequest
    {
        return Http::baseUrl(self::BASE_URL)
            ->withToken($this->token->value())
            ->timeout($this->timeout)
            ->retry(max(1, $this->retries), 250, throw: false)
            ->acceptJson();
    }

    private function throwUnlessSuccessful(Response $response): void
    {
        if ($response->successful()) {
            return;
        }

        /*
         * Errors arrive as a list of objects rather than strings, so the
         * message is assembled rather than imploded — otherwise every failure
         * reports "Array to string conversion" and says nothing.
         */
        $errors = $response->json();

        $message = is_array($errors)
            ? implode(' ', array_map(
                static fn (mixed $error): string => is_array($error)
                    ? (string) ($error['error_message'] ?? json_encode($error))
                    : (string) $error,
                $errors,
            ))
            : $response->body();

        throw MailingListException::requestFailed('constant_contact', $message, $response->status());
    }
}
