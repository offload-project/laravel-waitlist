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
 * Audienceful API v2.
 *
 * Contacts live on the workspace rather than on separate lists, so the list id
 * is a publication id (default) — Audienceful's consent stream — or a tag name
 * when the driver's `list_type` config is set to `tag`.
 *
 * Contacts are addressed by email in the request body rather than in the URL,
 * and writes are additive: tags and publications are never removed by a call
 * that does not name them.
 *
 * @see https://www.audienceful.com/help/api
 */
final class AudiencefulDriver implements MailingListDriver
{
    use ResolvesEntryAttributes;

    public function __construct(
        private readonly string $key,
        private readonly string $listType = 'publication',
        private readonly int $timeout = 10,
        private readonly int $retries = 2,
    ) {}

    public function name(): string
    {
        return 'audienceful';
    }

    public function subscribe(WaitlistEntry $entry, string $listId, array $options = []): Subscriber
    {
        $tags = $this->tags($options);

        if ($this->tagsAreLists()) {
            array_unshift($tags, $listId);
        }

        $payload = ['email' => $entry->email];

        if ($tags !== []) {
            $payload['tags'] = array_values(array_unique($tags));
        }

        $extraData = $this->extraData($entry, $options);

        if ($extraData !== []) {
            $payload['extra_data'] = $extraData;
        }

        // Only sent when opt-in is wanted, so the workspace's own setting
        // stands — and a contact already part way through the flow is left
        // where they are.
        if ($this->wantsDoubleOptIn($options)) {
            $payload['double_opt_in'] = 'required';
        }

        // Upserts: an email that already exists merges into that contact.
        $response = $this->request()->post('/people', $payload);

        $this->throwUnlessSuccessful($response);

        if (! $this->tagsAreLists()) {
            $this->consent($entry->email, $listId, true);
        }

        return $this->toSubscriber($response->json());
    }

    /**
     * Withdraw consent for the publication, or — when lists are tags, which
     * Audienceful cannot remove — unsubscribe the contact workspace wide.
     */
    public function unsubscribe(WaitlistEntry $entry, string $listId): void
    {
        if ($this->tagsAreLists()) {
            $this->swallowingMissingContact(
                $this->request()->post('/people/unsubscribe', ['email' => $entry->email])
            );

            return;
        }

        $this->consent($entry->email, $listId, false);
    }

    public function tag(WaitlistEntry $entry, string $listId, array $tags): void
    {
        if ($tags === []) {
            return;
        }

        // Tags are additive, so this adds to whatever the contact already has.
        $this->throwUnlessSuccessful(
            $this->request()->post('/people', [
                'email' => $entry->email,
                'tags' => $tags,
            ])
        );
    }

    public function find(string $email, string $listId): ?Subscriber
    {
        // Filtering the collection rather than fetching /people/{email}, which
        // would put the address in the URL.
        $response = $this->request()->get('/people', ['email' => $email]);

        $this->throwUnlessSuccessful($response);

        /** @var array<int, array<string, mixed>> $people */
        $people = $response->json('data', []);

        if ($people === []) {
            return null;
        }

        return $this->toSubscriber($people[0]);
    }

    private function request(): PendingRequest
    {
        return Http::baseUrl('https://api.audienceful.com/v2')
            ->withHeaders(['X-Api-Key' => $this->key])
            ->timeout($this->timeout)
            ->retry(max(1, $this->retries), 250, throw: false)
            ->acceptJson();
    }

    private function tagsAreLists(): bool
    {
        return $this->listType === 'tag';
    }

    /**
     * Grant or withdraw consent for a single publication. Only the ids named
     * here are touched.
     */
    private function consent(string $email, string $publicationId, bool $subscribed): void
    {
        $this->swallowingMissingContact(
            $this->request()->post('/people/publications', [
                'email' => $email,
                'publications' => [$publicationId => $subscribed],
            ])
        );
    }

    /**
     * Audienceful has no first and last name of its own, so the entry's name
     * lands in the `name` custom field alongside the mapped attributes.
     *
     * @param  array{attributes?: array<string, mixed>}  $options
     * @return array<string, mixed>
     */
    private function extraData(WaitlistEntry $entry, array $options): array
    {
        return array_merge(['name' => $entry->name], $this->attributes($entry, $options));
    }

    /**
     * @param  array<string, mixed>|null  $payload
     */
    private function toSubscriber(?array $payload): Subscriber
    {
        $payload ??= [];

        return new Subscriber(
            id: (string) ($payload['id'] ?? ''),
            email: (string) ($payload['email'] ?? ''),
            status: isset($payload['status']) ? (string) $payload['status'] : null,
            raw: $payload,
        );
    }

    /**
     * A contact that was never synced is already in the desired state.
     */
    private function swallowingMissingContact(Response $response): void
    {
        if ($response->status() === 404) {
            return;
        }

        $this->throwUnlessSuccessful($response);
    }

    private function throwUnlessSuccessful(Response $response): void
    {
        if ($response->successful()) {
            return;
        }

        throw MailingListException::requestFailed(
            'audienceful',
            (string) ($response->json('error.message') ?? $response->body()),
            $response->status(),
        );
    }
}
