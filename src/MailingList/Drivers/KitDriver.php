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
 * Kit (formerly ConvertKit) API v4.
 *
 * Kit has no concept of separate lists: subscribers live on the account and
 * are segmented by form or tag. The list id is therefore a form id (default)
 * or a tag id, depending on the driver's `list_type` config.
 *
 * @see https://developers.kit.com/api-reference/
 */
final class KitDriver implements MailingListDriver
{
    use ResolvesEntryAttributes;

    /** @var array<string, string> */
    private array $tagIds = [];

    public function __construct(
        private readonly string $key,
        private readonly string $listType = 'form',
        private readonly int $timeout = 10,
        private readonly int $retries = 2,
    ) {}

    public function name(): string
    {
        return 'kit';
    }

    public function subscribe(WaitlistEntry $entry, string $listId, array $options = []): Subscriber
    {
        // Upserts the subscriber so custom fields are stored before they are
        // attached to the form or tag.
        $response = $this->request()->post('/subscribers', array_filter([
            'email_address' => $entry->email,
            'first_name' => $this->firstName($entry),
            'fields' => $this->attributes($entry, $options),
        ]));

        $this->throwUnlessSuccessful($response);

        $subscriber = $this->toSubscriber($response->json('subscriber'));

        // Kit honours the form's own double opt-in setting here, so the
        // package's double_optin option does not apply to this driver.
        $this->throwUnlessSuccessful(
            $this->request()->post("/{$this->collection()}/{$listId}/subscribers", [
                'email_address' => $entry->email,
            ])
        );

        $tags = $this->tags($options);

        if ($tags !== []) {
            $this->tag($entry, $listId, $tags);
        }

        return $subscriber;
    }

    /**
     * Kit unsubscribes are account wide rather than per form.
     */
    public function unsubscribe(WaitlistEntry $entry, string $listId): void
    {
        $subscriberId = $this->subscriberId($entry, $listId);

        if ($subscriberId === null) {
            return;
        }

        $this->throwUnlessSuccessful(
            $this->request()->post("/subscribers/{$subscriberId}/unsubscribe")
        );
    }

    public function tag(WaitlistEntry $entry, string $listId, array $tags): void
    {
        foreach ($tags as $tag) {
            $this->throwUnlessSuccessful(
                $this->request()->post("/tags/{$this->tagId($tag)}/subscribers", [
                    'email_address' => $entry->email,
                ])
            );
        }
    }

    public function find(string $email, string $listId): ?Subscriber
    {
        $response = $this->request()->get('/subscribers', ['email_address' => $email]);

        $this->throwUnlessSuccessful($response);

        /** @var array<int, array<string, mixed>> $subscribers */
        $subscribers = $response->json('subscribers', []);

        if ($subscribers === []) {
            return null;
        }

        return $this->toSubscriber($subscribers[0]);
    }

    private function request(): PendingRequest
    {
        return Http::baseUrl('https://api.kit.com/v4')
            ->withHeaders(['X-Kit-Api-Key' => $this->key])
            ->timeout($this->timeout)
            ->retry(max(1, $this->retries), 250, throw: false)
            ->acceptJson();
    }

    private function collection(): string
    {
        return $this->listType === 'tag' ? 'tags' : 'forms';
    }

    private function subscriberId(WaitlistEntry $entry, string $listId): ?string
    {
        if ($entry->mailing_list_driver === $this->name() && filled($entry->mailing_list_subscriber_id)) {
            return $entry->mailing_list_subscriber_id;
        }

        return $this->find($entry->email, $listId)?->id;
    }

    /**
     * Resolve a tag name to its id, creating the tag when it does not exist.
     */
    private function tagId(string $tag): string
    {
        if (isset($this->tagIds[$tag])) {
            return $this->tagIds[$tag];
        }

        $response = $this->request()->get('/tags', ['per_page' => 500]);

        $this->throwUnlessSuccessful($response);

        /** @var array<int, array{id?: int|string, name?: string}> $existing */
        $existing = $response->json('tags', []);

        foreach ($existing as $candidate) {
            if (isset($candidate['name'], $candidate['id']) && $candidate['name'] === $tag) {
                return $this->tagIds[$tag] = (string) $candidate['id'];
            }
        }

        $created = $this->request()->post('/tags', ['name' => $tag]);

        $this->throwUnlessSuccessful($created);

        return $this->tagIds[$tag] = (string) $created->json('tag.id');
    }

    /**
     * @param  array<string, mixed>|null  $payload
     */
    private function toSubscriber(?array $payload): Subscriber
    {
        $payload ??= [];

        return new Subscriber(
            id: (string) ($payload['id'] ?? ''),
            email: (string) ($payload['email_address'] ?? ''),
            status: isset($payload['state']) ? (string) $payload['state'] : null,
            raw: $payload,
        );
    }

    private function throwUnlessSuccessful(Response $response): void
    {
        if ($response->successful()) {
            return;
        }

        /** @var array<int, string>|null $errors */
        $errors = $response->json('errors');

        throw MailingListException::requestFailed(
            'kit',
            $errors !== null ? implode(' ', $errors) : $response->body(),
            $response->status(),
        );
    }
}
