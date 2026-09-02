<?php

declare(strict_types=1);

namespace OffloadProject\Waitlist\MailingList\ConstantContact;

use Illuminate\Support\Facades\Cache;

/**
 * The default store: the application cache, seeded from config.
 *
 * Configuration supplies the token somebody authorised by hand, and it is used
 * only until the first exchange writes a replacement — from then on the cached
 * one wins, because the configured value is already spent.
 *
 * This asks nothing of the host application, which is the point. It also means
 * a cache flush loses the connection, so anything that cannot be re-authorised
 * on short notice should pass a store of its own.
 */
final readonly class CacheRefreshTokenStore implements RefreshTokenStore
{
    private const string KEY = 'waitlist.constant_contact.refresh_token';

    public function __construct(private ?string $seed = null) {}

    public function get(): ?string
    {
        $stored = Cache::get(self::KEY);

        if (is_string($stored) && $stored !== '') {
            return $stored;
        }

        return $this->seed !== '' ? $this->seed : null;
    }

    public function put(string $refreshToken): void
    {
        // Forever: it is the only copy once the configured seed is spent.
        Cache::forever(self::KEY, $refreshToken);
    }
}
