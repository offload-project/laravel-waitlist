<?php

declare(strict_types=1);

namespace OffloadProject\Waitlist\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use OffloadProject\Waitlist\Events\WaitlistCreated;

/**
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property string|null $description
 * @property bool $is_active
 * @property array<string, mixed>|null $settings
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
final class Waitlist extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'name',
        'slug',
        'description',
        'is_active',
        'settings',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'is_active' => 'boolean',
        'settings' => 'array',
    ];

    /** @var array<string, class-string> */
    protected $dispatchesEvents = [
        'created' => WaitlistCreated::class,
    ];

    /**
     * @return HasMany<WaitlistEntry, $this>
     */
    public function entries(): HasMany
    {
        return $this->hasMany(WaitlistEntry::class);
    }

    public function isActive(): bool
    {
        return $this->is_active;
    }

    public function activate(): self
    {
        $this->update(['is_active' => true]);

        return $this;
    }

    public function deactivate(): self
    {
        $this->update(['is_active' => false]);

        return $this;
    }

    /**
     * The mailing list this waitlist syncs into, if one has been connected.
     */
    public function mailingListId(): ?string
    {
        $listId = $this->mailingListSetting('list_id');

        return $listId === null ? null : (string) $listId;
    }

    /**
     * The driver this waitlist syncs with, or null to use the configured default.
     */
    public function mailingListDriver(): ?string
    {
        $driver = $this->mailingListSetting('driver');

        return $driver === null ? null : (string) $driver;
    }

    /**
     * Point this waitlist at a list, audience, or form on a mailing list service.
     */
    public function connectMailingList(string $listId, ?string $driver = null): self
    {
        $this->update([
            'settings' => array_merge($this->settings ?? [], [
                'mailing_list' => array_filter([
                    'list_id' => $listId,
                    'driver' => $driver,
                ], fn (?string $value): bool => $value !== null),
            ]),
        ]);

        return $this;
    }

    public function disconnectMailingList(): self
    {
        $settings = $this->settings ?? [];

        unset($settings['mailing_list']);

        $this->update(['settings' => $settings]);

        return $this;
    }

    private function mailingListSetting(string $key): mixed
    {
        /** @var array<string, mixed> $settings */
        $settings = $this->settings['mailing_list'] ?? [];

        return $settings[$key] ?? null;
    }
}
