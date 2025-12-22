<?php

declare(strict_types=1);

namespace OffloadProject\Waitlist;

use Illuminate\Database\Eloquent\Collection;
use OffloadProject\Waitlist\Models\WaitlistEntry;

final class WaitlistService
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function add(string $name, string $email, array $metadata = []): WaitlistEntry
    {
        return WaitlistEntry::create([
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
        return WaitlistEntry::where('status', 'pending')
            ->orderBy('created_at', 'asc')
            ->get();
    }

    /**
     * @return Collection<int, WaitlistEntry>
     */
    public function getInvited(): Collection
    {
        return WaitlistEntry::where('status', 'invited')
            ->orderBy('invited_at', 'desc')
            ->get();
    }

    /**
     * @return Collection<int, WaitlistEntry>
     */
    public function getAll(): Collection
    {
        return WaitlistEntry::orderBy('created_at', 'desc')->get();
    }

    public function getByEmail(string $email): ?WaitlistEntry
    {
        return WaitlistEntry::where('email', $email)->first();
    }

    public function exists(string $email): bool
    {
        return WaitlistEntry::where('email', $email)->exists();
    }

    public function count(): int
    {
        return WaitlistEntry::count();
    }

    public function countPending(): int
    {
        return WaitlistEntry::where('status', 'pending')->count();
    }

    public function countInvited(): int
    {
        return WaitlistEntry::where('status', 'invited')->count();
    }

    private function resolveEntry(int|WaitlistEntry $entry): WaitlistEntry
    {
        if ($entry instanceof WaitlistEntry) {
            return $entry;
        }

        return WaitlistEntry::findOrFail($entry);
    }
}
