<?php

declare(strict_types=1);

namespace OffloadProject\Waitlist\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int|null $waitlist_id
 * @property string $name
 * @property string $email
 * @property string $status
 * @property Carbon|null $invited_at
 * @property array<string, mixed>|null $metadata
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
final class WaitlistEntry extends Model
{
    use Notifiable;

    protected $fillable = [
        'waitlist_id',
        'name',
        'email',
        'status',
        'invited_at',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
        'invited_at' => 'datetime',
    ];

    /**
     * @return BelongsTo<Waitlist, $this>
     */
    public function waitlist(): BelongsTo
    {
        return $this->belongsTo(Waitlist::class);
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

        return $this;
    }

    public function markAsRejected(): self
    {
        $this->update([
            'status' => 'rejected',
        ]);

        return $this;
    }
}
