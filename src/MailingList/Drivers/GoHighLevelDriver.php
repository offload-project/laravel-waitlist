<?php

declare(strict_types=1);

namespace OffloadProject\Waitlist\MailingList\Drivers;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use OffloadProject\Waitlist\Contracts\MailingListDriver;
use OffloadProject\Waitlist\Exceptions\MailingListException;
use OffloadProject\Waitlist\MailingList\Concerns\ResolvesEntryAttributes;
use OffloadProject\Waitlist\MailingList\Subscriber;
use OffloadProject\Waitlist\Models\WaitlistEntry;

/**
 * HighLevel (GoHighLevel) API, version v3.
 *
 * HighLevel has no lists. A contact belongs to a location — a sub-account — and
 * is segmented by tag, so the list id here is the location id and tags do the
 * work a list would elsewhere.
 *
 * Tags are addressed by name and created on first use, so unlike ActiveCampaign
 * there is nothing to resolve before applying one. Custom fields are still
 * addressed by id, which is what most of the lookups below are for.
 *
 * @see https://marketplace.gohighlevel.com/docs/
 */
final class GoHighLevelDriver implements MailingListDriver
{
    use ResolvesEntryAttributes;

    private const string BASE_URL = 'https://services.leadconnectorhq.com';

    private const string VERSION = 'v3';

    /** @var array<string, array<string, string>> Location id to field name (lowercased) to field id. */
    private array $fieldIds = [];

    public function __construct(
        private readonly string $key,
        private readonly int $timeout = 10,
        private readonly int $retries = 2,
    ) {}

    public function name(): string
    {
        return 'gohighlevel';
    }

    public function subscribe(WaitlistEntry $entry, string $listId, array $options = []): Subscriber
    {
        /*
         * Upserted on the email address, so a returning sign-up updates rather
         * than duplicating. Email DND is lifted in the same call: signing up
         * again is the correct reading of somebody who had unsubscribed before.
         *
         * Tags are deliberately not sent here. On an upsert they replace every
         * tag the contact already has, which would strip whatever else the
         * account had marked them with.
         */
        $response = $this->request()->post('/contacts/upsert', array_filter([
            'locationId' => $listId,
            'email' => $entry->email,
            'firstName' => $this->firstName($entry),
            'lastName' => $this->lastName($entry),
            'source' => 'waitlist',
            'dndSettings' => ['email' => ['status' => 'inactive']],
            'customFields' => $this->customFields($entry, $listId, $options),
        ]));

        $this->throwUnlessSuccessful($response);

        $contactId = (string) $response->json('contact.id', '');

        $tags = $this->tags($options);

        if ($tags !== []) {
            $this->applyTags($contactId, $tags);
        }

        return new Subscriber(
            id: $contactId,
            email: $entry->email,
            status: 'active',
            raw: $response->json('contact') ?? [],
        );
    }

    /**
     * Turns on email DND for the contact, leaving the contact, its tags and its
     * other channels alone.
     *
     * The location is the list, so this is the whole of the unsubscribe: there
     * is no narrower membership to leave. Deleting the contact would take its
     * history and every other channel with it.
     */
    public function unsubscribe(WaitlistEntry $entry, string $listId): void
    {
        $contactId = $this->contactId($entry->email, $listId);

        if ($contactId === null) {
            return;
        }

        $this->throwUnlessSuccessful(
            $this->request()->put("/contacts/{$contactId}", [
                'dndSettings' => ['email' => ['status' => 'active']],
            ])
        );
    }

    public function tag(WaitlistEntry $entry, string $listId, array $tags): void
    {
        $contactId = $this->contactId($entry->email, $listId);

        if ($contactId === null) {
            return;
        }

        $this->applyTags($contactId, $tags);
    }

    public function find(string $email, string $listId): ?Subscriber
    {
        $contact = $this->contact($email, $listId);

        if ($contact === null) {
            return null;
        }

        return new Subscriber(
            id: (string) ($contact['id'] ?? ''),
            email: $email,
            status: ($contact['dndSettings']['email']['status'] ?? null) === 'active' ? 'unsubscribed' : 'active',
            raw: $contact,
        );
    }

    /**
     * Adds to the contact's tags rather than replacing them.
     *
     * @param  list<string>  $tags
     */
    private function applyTags(string $contactId, array $tags): void
    {
        $this->throwUnlessSuccessful(
            $this->request()->post("/contacts/{$contactId}/tags", ['tags' => $tags])
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    private function contact(string $email, string $locationId): ?array
    {
        $response = $this->request()->get('/contacts/search/duplicate', [
            'locationId' => $locationId,
            'email' => $email,
        ]);

        $this->throwUnlessSuccessful($response);

        $contact = $response->json('contact');

        return is_array($contact) ? $contact : null;
    }

    private function contactId(string $email, string $locationId): ?string
    {
        $id = $this->contact($email, $locationId)['id'] ?? null;

        return is_string($id) && $id !== '' ? $id : null;
    }

    /**
     * Attributes as HighLevel wants them: field id and value.
     *
     * An attribute with no matching field on the location is skipped rather
     * than sent. The alternative is a rejected contact, and losing the
     * annotation is better than losing the sign-up.
     *
     * @param  array{tags?: array<array-key, string>, double_optin?: bool, attributes?: array<string, mixed>}  $options
     * @return list<array{id: string, fieldValue: string}>
     */
    private function customFields(WaitlistEntry $entry, string $locationId, array $options): array
    {
        $attributes = $this->attributes($entry, $options);

        if ($attributes === []) {
            return [];
        }

        $values = [];

        foreach ($attributes as $name => $value) {
            $id = $this->fieldId($locationId, (string) $name);

            if ($id !== null) {
                $values[] = ['id' => $id, 'fieldValue' => (string) $value];
            }
        }

        return $values;
    }

    private function fieldId(string $locationId, string $name): ?string
    {
        if (! isset($this->fieldIds[$locationId])) {
            $response = $this->request()->get("/locations/{$locationId}/customFields", ['model' => 'contact']);

            $this->throwUnlessSuccessful($response);

            $this->fieldIds[$locationId] = [];

            /** @var array<int, array{id?: string, name?: string, fieldKey?: string}> $fields */
            $fields = $response->json('customFields', []);

            foreach ($fields as $field) {
                $id = $field['id'] ?? null;
                $fieldKey = $field['fieldKey'] ?? null;

                // Name is what a person sees; the field key is the merge tag
                // they may have written into the config instead, with or
                // without its `contact.` prefix.
                $keys = [
                    $field['name'] ?? null,
                    $fieldKey,
                    is_string($fieldKey) ? preg_replace('/^contact\./', '', $fieldKey) : null,
                ];

                foreach ($keys as $key) {
                    if (is_string($key) && is_string($id)) {
                        $this->fieldIds[$locationId][mb_strtolower($key)] = $id;
                    }
                }
            }
        }

        return $this->fieldIds[$locationId][mb_strtolower($name)] ?? null;
    }

    private function request(): PendingRequest
    {
        return Http::baseUrl(self::BASE_URL)
            ->withToken($this->key)
            ->withHeaders(['Version' => self::VERSION])
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
         * `message` is a string on most errors and a list of strings on a 422.
         * Deliberately untyped beyond that: this is an error path, and a
         * response shaped differently from the documented one is exactly the
         * case it has to survive.
         */
        $message = $response->json('message');

        $message = match (true) {
            is_string($message) => $message,
            is_array($message) => implode(' ', array_map(
                static fn (mixed $part): string => is_string($part) ? $part : (string) json_encode($part),
                $message,
            )),
            default => $response->body(),
        };

        throw MailingListException::requestFailed('gohighlevel', $message, $response->status());
    }
}
