<?php

declare(strict_types=1);

namespace OffloadProject\Waitlist;

use Illuminate\Database\Eloquent\Collection;
use OffloadProject\Waitlist\Models\Waitlist;
use OffloadProject\Waitlist\Models\WaitlistEntry;

final class WaitlistService
{
    private ?Waitlist $currentWaitlist = null;

    /**
     * Set the waitlist context by slug or ID
     */
    public function for(string|int|Waitlist $waitlist): self
    {
        $this->currentWaitlist = $this->resolveWaitlist($waitlist);

        return $this;
    }

    /**
     * Create a new waitlist
     */
    public function create(string $name, string $slug, ?string $description = null, bool $isActive = true): Waitlist
    {
        return Waitlist::create([
            'name' => $name,
            'slug' => $slug,
            'description' => $description,
            'is_active' => $isActive,
        ]);
    }

    /**
     * Get a waitlist by slug
     */
    public function find(string $slug): ?Waitlist
    {
        return Waitlist::where('slug', $slug)->first();
    }

    /**
     * Get or create the default waitlist
     */
    public function getDefault(): Waitlist
    {
        $slug = config('waitlist.default_slug', 'default');

        return Waitlist::firstOrCreate(
            ['slug' => $slug],
            [
                'name' => 'Default Waitlist',
                'description' => 'Default waitlist',
                'is_active' => true,
            ]
        );
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function add(string $name, string $email, array $metadata = []): WaitlistEntry
    {
        $waitlist = $this->currentWaitlist ?? $this->getDefault();

        return WaitlistEntry::create([
            'waitlist_id' => $waitlist->id,
            'name' => $name,
            'email' => $email,
            'metadata' => $metadata,
            'status' => 'pending',
        ]);
    }

    public function invite(int|WaitlistEntry $entry): WaitlistEntry
    {
        $entry = $this->resolveEntry($entry);
        $entry->markAsInvited();

        if (config('waitlist.auto_send_invitation', true)) {
            $notificationClass = config('waitlist.notification');
            $entry->notify(new $notificationClass($entry));
        }

        return $entry;
    }

    public function reject(int|WaitlistEntry $entry): WaitlistEntry
    {
        $entry = $this->resolveEntry($entry);
        $entry->markAsRejected();

        return $entry;
    }

    /**
     * @return Collection<int, WaitlistEntry>
     */
    public function getPending(): Collection
    {
        return $this->query()
            ->where('status', 'pending')
            ->orderBy('created_at', 'asc')
            ->get();
    }

    /**
     * @return Collection<int, WaitlistEntry>
     */
    public function getInvited(): Collection
    {
        return $this->query()
            ->where('status', 'invited')
            ->orderBy('invited_at', 'desc')
            ->get();
    }

    /**
     * @return Collection<int, WaitlistEntry>
     */
    public function getAll(): Collection
    {
        return $this->query()
            ->orderBy('created_at', 'desc')
            ->get();
    }

    public function getByEmail(string $email): ?WaitlistEntry
    {
        return $this->query()
            ->where('email', $email)
            ->first();
    }

    public function exists(string $email): bool
    {
        return $this->query()
            ->where('email', $email)
            ->exists();
    }

    public function count(): int
    {
        return $this->query()->count();
    }

    public function countPending(): int
    {
        return $this->query()
            ->where('status', 'pending')
            ->count();
    }

    public function countInvited(): int
    {
        return $this->query()
            ->where('status', 'invited')
            ->count();
    }

    /**
     * Get base query for current waitlist context
     *
     * @return \Illuminate\Database\Eloquent\Builder<WaitlistEntry>
     */
    private function query(): \Illuminate\Database\Eloquent\Builder
    {
        $waitlist = $this->currentWaitlist ?? $this->getDefault();

        return WaitlistEntry::where('waitlist_id', $waitlist->id);
    }

    private function resolveWaitlist(string|int|Waitlist $waitlist): Waitlist
    {
        if ($waitlist instanceof Waitlist) {
            return $waitlist;
        }

        if (is_int($waitlist)) {
            return Waitlist::findOrFail($waitlist);
        }

        return Waitlist::where('slug', $waitlist)->firstOrFail();
    }

    private function resolveEntry(int|WaitlistEntry $entry): WaitlistEntry
    {
        if ($entry instanceof WaitlistEntry) {
            return $entry;
        }

        return WaitlistEntry::findOrFail($entry);
    }
}
