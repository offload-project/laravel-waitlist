<?php

declare(strict_types=1);

namespace OffloadProject\Waitlist\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Notifiable;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property string $status
 * @property CarbonInterface|null $invited_at
 * @property array<string, mixed>|null $metadata
 * @property CarbonInterface $created_at
 * @property CarbonInterface $updated_at
 */
final class WaitlistEntry extends Model
{
    use Notifiable;

    protected $fillable = [
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
