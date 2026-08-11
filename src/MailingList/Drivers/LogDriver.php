<?php

declare(strict_types=1);

namespace OffloadProject\Waitlist\MailingList\Drivers;

use Illuminate\Support\Facades\Log;
use OffloadProject\Waitlist\Contracts\MailingListDriver;
use OffloadProject\Waitlist\MailingList\Subscriber;
use OffloadProject\Waitlist\Models\WaitlistEntry;

/**
 * Writes what would have been sent to the log. Useful in local and staging
 * environments where you do not want to touch a real audience.
 */
final class LogDriver implements MailingListDriver
{
    public function __construct(
        private readonly ?string $channel = null,
    ) {}

    public function name(): string
    {
        return 'log';
    }

    public function subscribe(WaitlistEntry $entry, string $listId, array $options = []): Subscriber
    {
        $this->log('subscribe', $entry, $listId, $options);

        return new Subscriber(
            id: md5(mb_strtolower($entry->email)),
            email: $entry->email,
            status: 'subscribed',
        );
    }

    public function unsubscribe(WaitlistEntry $entry, string $listId): void
    {
        $this->log('unsubscribe', $entry, $listId);
    }

    public function tag(WaitlistEntry $entry, string $listId, array $tags): void
    {
        $this->log('tag', $entry, $listId, ['tags' => $tags]);
    }

    public function find(string $email, string $listId): ?Subscriber
    {
        return null;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function log(string $action, WaitlistEntry $entry, string $listId, array $context = []): void
    {
        Log::channel($this->channel ?? config('logging.default'))->info(
            "Waitlist mailing list [{$action}]",
            array_merge([
                'entry_id' => $entry->id,
                'email' => $entry->email,
                'list_id' => $listId,
            ], $context)
        );
    }
}
