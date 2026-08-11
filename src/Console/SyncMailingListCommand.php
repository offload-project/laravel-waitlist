<?php

declare(strict_types=1);

namespace OffloadProject\Waitlist\Console;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use OffloadProject\Waitlist\MailingList\MailingListManager;
use OffloadProject\Waitlist\Models\Waitlist;
use OffloadProject\Waitlist\WaitlistService;

final class SyncMailingListCommand extends Command
{
    /** @var string */
    protected $signature = 'waitlist:sync-mailing-list
                            {waitlist? : The slug of the waitlist to sync}
                            {--all : Sync every waitlist}
                            {--force : Re-sync entries that have already been synced}';

    /** @var string */
    protected $description = 'Push waitlist entries to their connected mailing list';

    public function handle(WaitlistService $waitlist, MailingListManager $mailingList): int
    {
        if (! $mailingList->enabled()) {
            $this->components->error('Mailing list syncing is disabled. Set [waitlist.mailing_list.enabled] to true.');

            return self::FAILURE;
        }

        $waitlists = $this->waitlists($waitlist);

        if ($waitlists->isEmpty()) {
            $this->components->error('No matching waitlist found.');

            return self::FAILURE;
        }

        $total = 0;

        foreach ($waitlists as $list) {
            if ($mailingList->listIdFor($list) === null) {
                $this->components->twoColumnDetail($list->name, '<fg=yellow>no list connected</>');

                continue;
            }

            $queued = $waitlist->for($list)->syncMailingList((bool) $this->option('force'));
            $total += $queued;

            $this->components->twoColumnDetail(
                "{$list->name} <fg=gray>({$mailingList->driverNameFor($list)})</>",
                "{$queued} queued"
            );
        }

        $this->components->info("Queued {$total} ".str('entry')->plural($total).' for syncing.');

        return self::SUCCESS;
    }

    /**
     * @return Collection<int, Waitlist>
     */
    private function waitlists(WaitlistService $waitlist): Collection
    {
        if ($this->option('all')) {
            return Waitlist::all();
        }

        $slug = $this->argument('waitlist');

        if (! is_string($slug)) {
            return new Collection([$waitlist->getDefault()]);
        }

        return Waitlist::where('slug', $slug)->get();
    }
}
