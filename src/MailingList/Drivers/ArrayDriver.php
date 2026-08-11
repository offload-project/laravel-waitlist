<?php

declare(strict_types=1);

namespace OffloadProject\Waitlist\MailingList\Drivers;

use OffloadProject\Waitlist\Contracts\MailingListDriver;
use OffloadProject\Waitlist\MailingList\Concerns\ResolvesEntryAttributes;
use OffloadProject\Waitlist\MailingList\Subscriber;
use OffloadProject\Waitlist\Models\WaitlistEntry;

/**
 * In-memory driver used by MailingList::fake() so tests can assert on what
 * would have been sent without hitting a real service.
 */
final class ArrayDriver implements MailingListDriver
{
    use ResolvesEntryAttributes;

    /** @var array<string, array<string, Subscriber>> */
    private array $subscribers = [];

    /** @var array<string, array<string, list<string>>> */
    private array $tags = [];

    public function name(): string
    {
        return 'array';
    }

    public function subscribe(WaitlistEntry $entry, string $listId, array $options = []): Subscriber
    {
        $subscriber = new Subscriber(
            id: md5(mb_strtolower($entry->email)),
            email: $entry->email,
            status: $this->wantsDoubleOptIn($options) ? 'pending' : 'subscribed',
            raw: ['attributes' => $this->attributes($entry, $options)],
        );

        $this->subscribers[$listId][$this->key($entry->email)] = $subscriber;

        $tags = $this->tags($options);

        if ($tags !== []) {
            $this->tag($entry, $listId, $tags);
        }

        return $subscriber;
    }

    public function unsubscribe(WaitlistEntry $entry, string $listId): void
    {
        unset(
            $this->subscribers[$listId][$this->key($entry->email)],
            $this->tags[$listId][$this->key($entry->email)],
        );
    }

    public function tag(WaitlistEntry $entry, string $listId, array $tags): void
    {
        $existing = $this->tags[$listId][$this->key($entry->email)] ?? [];

        $this->tags[$listId][$this->key($entry->email)] = array_values(
            array_unique(array_merge($existing, $tags))
        );
    }

    public function find(string $email, string $listId): ?Subscriber
    {
        return $this->subscribers[$listId][$this->key($email)] ?? null;
    }

    /**
     * Every subscriber on a list, or across all lists when no list is given.
     *
     * @return array<string, Subscriber>
     */
    public function subscribers(?string $listId = null): array
    {
        if ($listId !== null) {
            return $this->subscribers[$listId] ?? [];
        }

        return array_merge(...array_values($this->subscribers)) ?: [];
    }

    public function hasSubscriber(string $email, ?string $listId = null): bool
    {
        return array_key_exists($this->key($email), $this->subscribers($listId));
    }

    /**
     * @return list<string>
     */
    public function tagsFor(string $email, string $listId): array
    {
        return $this->tags[$listId][$this->key($email)] ?? [];
    }

    public function flush(): void
    {
        $this->subscribers = [];
        $this->tags = [];
    }

    private function key(string $email): string
    {
        return mb_strtolower($email);
    }
}
