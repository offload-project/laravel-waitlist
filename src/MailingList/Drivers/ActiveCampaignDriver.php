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
 * ActiveCampaign API v3.
 *
 * The list id is a numeric ActiveCampaign list id. Unlike Kit, which segments
 * one pool of subscribers by form or tag, ActiveCampaign has real lists and a
 * contact's membership of one carries its own status.
 *
 * Everything here is addressed by id — lists, tags and custom fields — so a
 * name has to be resolved before it can be used. That is the shape of most of
 * the code below.
 *
 * @see https://developers.activecampaign.com/reference
 */
final class ActiveCampaignDriver implements MailingListDriver
{
    use ResolvesEntryAttributes;

    /** Membership states on a list. */
    private const int STATUS_ACTIVE = 1;

    private const int STATUS_UNSUBSCRIBED = 2;

    /** @var array<string, string>|null Field name (lowercased) to field id. */
    private ?array $fieldIds = null;

    /** @var array<string, string> Tag name (lowercased) to tag id. */
    private array $tagIds = [];

    public function __construct(
        private readonly string $key,
        private readonly string $url,
        private readonly int $timeout = 10,
        private readonly int $retries = 2,
    ) {}

    public function name(): string
    {
        return 'activecampaign';
    }

    public function subscribe(WaitlistEntry $entry, string $listId, array $options = []): Subscriber
    {
        /*
         * `contact/sync` upserts on the email address, so a returning sign-up
         * updates rather than duplicating. Custom fields go in the same call —
         * they are part of the contact, not a second request.
         */
        $response = $this->request()->post('/contact/sync', [
            'contact' => array_filter([
                'email' => $entry->email,
                'firstName' => $this->firstName($entry),
                'fieldValues' => $this->fieldValues($entry, $options),
            ]),
        ]);

        $this->throwUnlessSuccessful($response);

        $contactId = (string) $response->json('contact.id', '');

        /*
         * Membership is its own resource, so the sync above creates the contact
         * and this puts them on the list. Status 1 re-activates somebody who
         * had unsubscribed from this list before, which is the correct reading
         * of them signing up again.
         */
        $this->throwUnlessSuccessful(
            $this->request()->post('/contactLists', [
                'contactList' => [
                    'list' => $listId,
                    'contact' => $contactId,
                    'status' => self::STATUS_ACTIVE,
                ],
            ])
        );

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
     * Takes them off this list, leaving the contact and any other lists alone.
     *
     * Status 2 rather than a delete: ActiveCampaign keeps the membership row so
     * the unsubscribe is a fact about this list, and deleting the contact would
     * remove them from every other one as well.
     */
    public function unsubscribe(WaitlistEntry $entry, string $listId): void
    {
        $contactId = $this->contactId($entry->email);

        if ($contactId === null) {
            return;
        }

        $this->throwUnlessSuccessful(
            $this->request()->post('/contactLists', [
                'contactList' => [
                    'list' => $listId,
                    'contact' => $contactId,
                    'status' => self::STATUS_UNSUBSCRIBED,
                ],
            ])
        );
    }

    public function tag(WaitlistEntry $entry, string $listId, array $tags): void
    {
        $contactId = $this->contactId($entry->email);

        if ($contactId === null) {
            return;
        }

        $this->applyTags($contactId, $tags);
    }

    public function find(string $email, string $listId): ?Subscriber
    {
        $contact = $this->contact($email);

        if ($contact === null) {
            return null;
        }

        return new Subscriber(
            id: (string) ($contact['id'] ?? ''),
            email: $email,
            status: isset($contact['status']) ? (string) $contact['status'] : null,
            raw: $contact,
        );
    }

    /**
     * @param  list<string>  $tags
     */
    private function applyTags(string $contactId, array $tags): void
    {
        foreach ($tags as $tag) {
            $this->throwUnlessSuccessful(
                $this->request()->post('/contactTags', [
                    'contactTag' => ['contact' => $contactId, 'tag' => $this->tagId($tag)],
                ])
            );
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function contact(string $email): ?array
    {
        $response = $this->request()->get('/contacts', ['email' => $email]);

        $this->throwUnlessSuccessful($response);

        /** @var array<int, array<string, mixed>> $contacts */
        $contacts = $response->json('contacts', []);

        return $contacts[0] ?? null;
    }

    private function contactId(string $email): ?string
    {
        $id = $this->contact($email)['id'] ?? null;

        return is_string($id) && $id !== '' ? $id : null;
    }

    /**
     * Attributes as ActiveCampaign wants them: field id and value.
     *
     * An attribute with no matching field on the account is skipped rather than
     * sent. The alternative is a rejected contact, and losing the annotation is
     * better than losing the sign-up.
     *
     * @param  array{tags?: array<array-key, string>, double_optin?: bool, attributes?: array<string, mixed>}  $options
     * @return list<array{field: string, value: string}>
     */
    private function fieldValues(WaitlistEntry $entry, array $options): array
    {
        $attributes = $this->attributes($entry, $options);

        if ($attributes === []) {
            return [];
        }

        $values = [];

        foreach ($attributes as $name => $value) {
            $id = $this->fieldId((string) $name);

            if ($id !== null) {
                $values[] = ['field' => $id, 'value' => (string) $value];
            }
        }

        return $values;
    }

    private function fieldId(string $name): ?string
    {
        if ($this->fieldIds === null) {
            $response = $this->request()->get('/fields', ['limit' => 100]);

            $this->throwUnlessSuccessful($response);

            $this->fieldIds = [];

            /** @var array<int, array{id?: string, title?: string, perstag?: string}> $fields */
            $fields = $response->json('fields', []);

            foreach ($fields as $field) {
                $id = $field['id'] ?? null;

                // Title is what a person sees; perstag is the merge tag they
                // may have written into the config instead.
                foreach ([$field['title'] ?? null, $field['perstag'] ?? null] as $key) {
                    if (is_string($key) && is_string($id)) {
                        $this->fieldIds[mb_strtolower($key)] = $id;
                    }
                }
            }
        }

        return $this->fieldIds[mb_strtolower($name)] ?? null;
    }

    /**
     * Resolve a tag name to its id, creating the tag when it does not exist.
     *
     * Searched before created because ActiveCampaign refuses a duplicate, and
     * matched case-insensitively because it treats tags that way itself — so a
     * blind create would fail on the second sign-up rather than returning the
     * existing id.
     */
    private function tagId(string $tag): string
    {
        $key = mb_strtolower($tag);

        if (isset($this->tagIds[$key])) {
            return $this->tagIds[$key];
        }

        $response = $this->request()->get('/tags', ['search' => $tag, 'limit' => 100]);

        $this->throwUnlessSuccessful($response);

        /** @var array<int, array{id?: string, tag?: string}> $existing */
        $existing = $response->json('tags', []);

        foreach ($existing as $candidate) {
            if (isset($candidate['tag'], $candidate['id']) && mb_strtolower($candidate['tag']) === $key) {
                return $this->tagIds[$key] = (string) $candidate['id'];
            }
        }

        $created = $this->request()->post('/tags', [
            'tag' => ['tag' => $tag, 'tagType' => 'contact'],
        ]);

        $this->throwUnlessSuccessful($created);

        return $this->tagIds[$key] = (string) $created->json('tag.id');
    }

    private function request(): PendingRequest
    {
        return Http::baseUrl(rtrim($this->url, '/').'/api/3')
            ->withHeaders(['Api-Token' => $this->key])
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
         * Errors come back as a list of objects under `errors`, each with a
         * `title`. Assembled rather than imploded: imploding a list of arrays
         * reports "Array to string conversion" and says nothing about what was
         * actually refused.
         */
        /*
         * Deliberately untyped beyond "a list of something": this is an error
         * path, and a response shaped differently from the documented one is
         * exactly the case it has to survive.
         *
         * @var array<int, mixed>|null $errors
         */
        $errors = $response->json('errors');

        $message = is_array($errors)
            ? implode(' ', array_map(
                static fn (mixed $error): string => is_array($error)
                    ? (string) ($error['title'] ?? json_encode($error))
                    : (string) $error,
                $errors,
            ))
            : $response->body();

        throw MailingListException::requestFailed('activecampaign', $message, $response->status());
    }
}
