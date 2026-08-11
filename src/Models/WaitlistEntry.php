<?php

declare(strict_types=1);

namespace OffloadProject\Waitlist\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use OffloadProject\InviteOnly\Models\Invitation;
use OffloadProject\Waitlist\Events\WaitlistEntryAdded;
use OffloadProject\Waitlist\Events\WaitlistEntryInvited;
use OffloadProject\Waitlist\Events\WaitlistEntryRejected;
use OffloadProject\Waitlist\Events\WaitlistEntryVerified;

/**
 * @property int $id
 * @property int|null $waitlist_id
 * @property string $name
 * @property string $email
 * @property string $status
 * @property Carbon|null $invited_at
 * @property array<string, mixed>|null $metadata
 * @property string|null $verification_token
 * @property Carbon|null $verified_at
 * @property int|null $invitation_id
 * @property string|null $mailing_list_driver
 * @property string|null $mailing_list_subscriber_id
 * @property Carbon|null $mailing_list_synced_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
final class WaitlistEntry extends Model
{
    use Notifiable;

    /** @var list<string> */
    protected $fillable = [
        'waitlist_id',
        'name',
        'email',
        'status',
        'invited_at',
        'metadata',
        'verification_token',
        'verified_at',
        'invitation_id',
        'mailing_list_driver',
        'mailing_list_subscriber_id',
        'mailing_list_synced_at',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'metadata' => 'array',
        'invited_at' => 'datetime',
        'verified_at' => 'datetime',
        'mailing_list_synced_at' => 'datetime',
    ];

    /** @var array<string, class-string> */
    protected $dispatchesEvents = [
        'created' => WaitlistEntryAdded::class,
    ];

    /**
     * @return BelongsTo<Waitlist, $this>
     */
    public function waitlist(): BelongsTo
    {
        return $this->belongsTo(Waitlist::class);
    }

    /**
     * @return BelongsTo<Invitation, $this>
     */
    public function invitation(): BelongsTo
    {
        return $this->belongsTo(Invitation::class);
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function isInvited(): bool
    {
        return $this->status === 'invited';
    }

    public function isRejected(): bool
    {
        return $this->status === 'rejected';
    }

    public function markAsInvited(): self
    {
        $this->update([
            'status' => 'invited',
            'invited_at' => now(),
        ]);

        event(new WaitlistEntryInvited($this));

        return $this;
    }

    public function markAsRejected(): self
    {
        $this->update([
            'status' => 'rejected',
        ]);

        event(new WaitlistEntryRejected($this));

        return $this;
    }

    public function isVerified(): bool
    {
        return $this->verified_at !== null;
    }

    public function isPendingVerification(): bool
    {
        return $this->verification_token !== null && $this->verified_at === null;
    }

    public function markAsVerified(): self
    {
        $this->update([
            'verified_at' => now(),
            'verification_token' => null,
        ]);

        event(new WaitlistEntryVerified($this));

        return $this;
    }

    public function generateVerificationToken(): self
    {
        $this->update([
            'verification_token' => bin2hex(random_bytes(32)),
        ]);

        return $this;
    }

    public function isSubscribedToMailingList(): bool
    {
        return $this->mailing_list_synced_at !== null;
    }

    public function markAsSubscribed(string $driver, string $subscriberId): self
    {
        $this->update([
            'mailing_list_driver' => $driver,
            'mailing_list_subscriber_id' => $subscriberId,
            'mailing_list_synced_at' => now(),
        ]);

        return $this;
    }

    public function markAsUnsubscribed(): self
    {
        $this->update([
            'mailing_list_subscriber_id' => null,
            'mailing_list_synced_at' => null,
        ]);

        return $this;
    }
}
