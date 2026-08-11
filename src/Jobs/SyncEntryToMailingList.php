<?php

declare(strict_types=1);

namespace OffloadProject\Waitlist\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use OffloadProject\Waitlist\Events\MailingListSyncFailed;
use OffloadProject\Waitlist\Events\WaitlistEntrySubscribed;
use OffloadProject\Waitlist\Exceptions\MailingListException;
use OffloadProject\Waitlist\MailingList\MailingListManager;
use OffloadProject\Waitlist\Models\WaitlistEntry;
use Throwable;

final class SyncEntryToMailingList implements ShouldQueue
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
     * Queue the sync, or run it inline when queueing is turned off.
     */
    public static function dispatchFor(WaitlistEntry $entry): void
    {
        $job = new self($entry);

        if (! config('waitlist.mailing_list.queue.enabled', true)) {
            dispatch_sync($job);

            return;
        }

        // Entries are often created inside a transaction, so wait for the
        // commit rather than letting a worker beat it to the row.
        dispatch($job)
            ->afterCommit()
            ->onConnection(config('waitlist.mailing_list.queue.connection'))
            ->onQueue(config('waitlist.mailing_list.queue.queue'));
    }

    public function handle(MailingListManager $mailingList): void
    {
        $waitlist = $this->entry->waitlist;
        $driver = $mailingList->driverNameFor($waitlist);
        $listId = $mailingList->listIdFor($waitlist);

        if ($listId === null) {
            // A missing list id is a configuration problem: retrying it will
            // never succeed, so report it and stop here.
            $exception = MailingListException::missingListId($driver);

            report($exception);
            event(new MailingListSyncFailed($this->entry, $driver, $exception));

            return;
        }

        try {
            $subscriber = $mailingList->driver($driver)->subscribe($this->entry, $listId);
        } catch (Throwable $exception) {
            event(new MailingListSyncFailed($this->entry, $driver, $exception));

            throw $exception;
        }

        $this->entry->markAsSubscribed($driver, $subscriber->id);

        event(new WaitlistEntrySubscribed($this->entry, $subscriber, $driver));
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [10, 60];
    }
}
