<?php

declare(strict_types=1);

namespace OffloadProject\Waitlist\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use OffloadProject\Waitlist\Events\MailingListSyncFailed;
use OffloadProject\Waitlist\Events\WaitlistEntryUnsubscribed;
use OffloadProject\Waitlist\MailingList\MailingListManager;
use OffloadProject\Waitlist\Models\WaitlistEntry;
use Throwable;

final class UnsubscribeEntryFromMailingList implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public function __construct(
        public WaitlistEntry $entry
    ) {}

    /**
     * Queue the removal, or run it inline when queueing is turned off.
     */
    public static function dispatchFor(WaitlistEntry $entry): void
    {
        $job = new self($entry);

        if (! config('waitlist.mailing_list.queue.enabled', true)) {
            dispatch_sync($job);

            return;
        }

        dispatch($job)
            ->afterCommit()
            ->onConnection(config('waitlist.mailing_list.queue.connection'))
            ->onQueue(config('waitlist.mailing_list.queue.queue'));
    }

    public function handle(MailingListManager $mailingList): void
    {
        $waitlist = $this->entry->waitlist;
        $driver = $this->entry->mailing_list_driver ?? $mailingList->driverNameFor($waitlist);
        $listId = $mailingList->listIdFor($waitlist);

        if ($listId === null) {
            return;
        }

        try {
            $mailingList->driver($driver)->unsubscribe($this->entry, $listId);
        } catch (Throwable $exception) {
            event(new MailingListSyncFailed($this->entry, $driver, $exception));

            throw $exception;
        }

        $this->entry->markAsUnsubscribed();

        event(new WaitlistEntryUnsubscribed($this->entry, $driver));
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [10, 60];
    }
}
