<?php

declare(strict_types=1);

namespace OffloadProject\Waitlist\MailingList\ConstantContact;

/**
 * Where the Constant Contact refresh token lives between requests.
 *
 * It has to be writable, not just readable: every exchange issues a new refresh
 * token and invalidates the one used, so a store that cannot be written — an
 * environment variable, say — works exactly once and then locks the account
 * out until it is authorised again by hand.
 */
interface RefreshTokenStore
{
    public function get(): ?string;

    public function put(string $refreshToken): void;
}
