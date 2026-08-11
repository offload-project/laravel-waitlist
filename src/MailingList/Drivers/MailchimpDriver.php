<?php

declare(strict_types=1);

namespace OffloadProject\Waitlist\MailingList\Drivers;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use OffloadProject\Waitlist\Contracts\MailingListDriver;
use OffloadProject\Waitlist\Exceptions\MailingListException;
use OffloadProject\Waitlist\MailingList\Concerns\ResolvesEntryAttributes;
use OffloadProject\Waitlist\MailingList\Subscriber;
use OffloadProject\Waitlist\Models\WaitlistEntry;

/**
 * Mailchimp Marketing API v3.
 *
 * The list id is an audience id, found under Audience > Settings > Audience
 * name and defaults in the Mailchimp dashboard.
 *
 * @see https://mailchimp.com/developer/marketing/api/list-members/
 */
final class MailchimpDriver implements MailingListDriver
{
    use ResolvesEntryAttributes;

    public function __construct(
        private readonly string $key,
        private readonly ?string $server = null,
        private readonly int $timeout = 10,
        private readonly int $retries = 2,
    ) {}

    public function name(): string
    {
        return 'mailchimp';
    }

    public function subscribe(WaitlistEntry $entry, string $listId, array $options = []): Subscriber
    {
        $payload = [
            'email_address' => $entry->email,
            // Only sent on create, so an existing unsubscribe is never overridden.
            'status_if_new' => $this->wantsDoubleOptIn($options) ? 'pending' : 'subscribed',
            'merge_fields' => $this->mergeFields($entry, $options),
        ];

        $response = $this->request()->put($this->memberPath($listId, $entry->email), $payload);

        $this->throwUnlessSuccessful($response);

        $tags = $this->tags($options);

        if ($tags !== []) {
            $this->tag($entry, $listId, $tags);
        }

        return $this->toSubscriber($response->json());
    }

    public function unsubscribe(WaitlistEntry $entry, string $listId): void
    {
        $response = $this->request()->patch($this->memberPath($listId, $entry->email), [
            'status' => 'unsubscribed',
        ]);

        // A contact that was never synced is already in the desired state.
        if ($response->status() === 404) {
            return;
        }

        $this->throwUnlessSuccessful($response);
    }

    public function tag(WaitlistEntry $entry, string $listId, array $tags): void
    {
        if ($tags === []) {
            return;
        }

        $response = $this->request()->post($this->memberPath($listId, $entry->email).'/tags', [
            'tags' => array_map(
                fn (string $tag): array => ['name' => $tag, 'status' => 'active'],
                $tags
            ),
        ]);

        $this->throwUnlessSuccessful($response);
    }

    public function find(string $email, string $listId): ?Subscriber
    {
        $response = $this->request()->get($this->memberPath($listId, $email));

        if ($response->status() === 404) {
            return null;
        }

        $this->throwUnlessSuccessful($response);

        return $this->toSubscriber($response->json());
    }

    private function request(): PendingRequest
    {
        return Http::baseUrl("https://{$this->server()}.api.mailchimp.com/3.0")
            ->withBasicAuth('laravel-waitlist', $this->key)
            ->timeout($this->timeout)
            ->retry(max(1, $this->retries), 250, throw: false)
            ->acceptJson();
    }

    /**
     * Mailchimp derives the data centre from the suffix of the API key.
     */
    private function server(): string
    {
        if (filled($this->server)) {
            return $this->server;
        }

        $suffix = Str::afterLast($this->key, '-');

        if ($suffix === '' || $suffix === $this->key) {
            throw MailingListException::missingCredentials('mailchimp', 'server');
        }

        return $suffix;
    }

    private function memberPath(string $listId, string $email): string
    {
        return "/lists/{$listId}/members/".md5(mb_strtolower($email));
    }

    /**
     * @param  array{attributes?: array<string, mixed>}  $options
     * @return array<string, mixed>
     */
    private function mergeFields(WaitlistEntry $entry, array $options): array
    {
        return array_merge([
            'FNAME' => $this->firstName($entry),
            'LNAME' => $this->lastName($entry),
        ], $this->attributes($entry, $options));
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
            status: isset($payload['status']) ? (string) $payload['status'] : null,
            raw: $payload,
        );
    }

    private function throwUnlessSuccessful(Response $response): void
    {
        if ($response->successful()) {
            return;
        }

        throw MailingListException::requestFailed(
            'mailchimp',
            (string) ($response->json('detail') ?? $response->body()),
            $response->status(),
        );
    }
}
