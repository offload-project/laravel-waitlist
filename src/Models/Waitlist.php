<?php

declare(strict_types=1);

namespace OffloadProject\Waitlist\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

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
    protected $fillable = [
        'name',
        'slug',
        'description',
        'is_active',
        'settings',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'settings' => 'array',
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
}
