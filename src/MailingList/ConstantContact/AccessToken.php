<?php

declare(strict_types=1);

namespace OffloadProject\Waitlist\MailingList\ConstantContact;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use OffloadProject\Waitlist\Exceptions\MailingListException;

/**
 * Keeps a usable Constant Contact access token in hand.
 *
 * Constant Contact is the only provider here that cannot be driven by a static
 * key. Its V3 API is OAuth2 and every one of its flows — authorization code,
 * PKCE, device, implicit — needs a person at a browser; there is no
 * client-credentials flow. So an application cannot obtain its first token on
 * its own, and this class does not try. It is given a refresh token that
 * somebody authorised once, and its job is to keep trading that in.
 *
 * Two lifetimes matter:
 *
 * - An access token lasts 24 hours. It is cached for slightly less, so a token
 *   is never presented in the seconds around its expiry.
 * - A refresh token lasts 180 days *if never used*, and each exchange issues a
 *   new one. Using it therefore keeps the connection alive, and losing the
 *   replacement ends it — which is why the new refresh token is written back to
 *   the store before the access token is returned.
 *
 * The store is the cache by default, which makes this work with no migration.
 * A cache flush loses the refresh token and the connection has to be authorised
 * again by hand, so an application that cannot tolerate that should hand in a
 * store backed by its own table.
 */
final class AccessToken
{
    private const string TOKEN_URL = 'https://authz.constantcontact.com/oauth2/default/v1/token';

    /** A margin against clock drift and time spent in flight. */
    private const int EXPIRY_MARGIN_SECONDS = 300;

    public function __construct(
        private readonly string $clientId,
        private readonly string $clientSecret,
        private readonly RefreshTokenStore $store,
        private readonly int $timeout = 10,
    ) {}

    public function value(): string
    {
        $cached = Cache::get($this->cacheKey());

        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        return $this->refresh();
    }

    /**
     * Trade the stored refresh token for a new pair.
     */
    public function refresh(): string
    {
        $refreshToken = $this->store->get();

        if ($refreshToken === null || $refreshToken === '') {
            throw MailingListException::missingCredentials('constant_contact', 'refresh_token');
        }

        $response = Http::asForm()
            ->withBasicAuth($this->clientId, $this->clientSecret)
            ->timeout($this->timeout)
            ->acceptJson()
            ->post(self::TOKEN_URL, [
                'grant_type' => 'refresh_token',
                'refresh_token' => $refreshToken,
            ]);

        if ($response->failed()) {
            /*
             * Worth saying plainly, because the way out is not a retry. A
             * refused refresh token means the connection is over until somebody
             * authorises the account again in a browser.
             */
            throw MailingListException::requestFailed(
                'constant_contact',
                'The refresh token was refused, so the account has to be authorised again: '.$response->body(),
                $response->status(),
            );
        }

        $accessToken = (string) $response->json('access_token', '');

        if ($accessToken === '') {
            throw MailingListException::requestFailed(
                'constant_contact',
                'The token response carried no access_token: '.$response->body(),
                $response->status(),
            );
        }

        // Before the access token is handed out: if this write is lost, so is
        // the connection.
        $rotated = $response->json('refresh_token');

        if (is_string($rotated) && $rotated !== '') {
            $this->store->put($rotated);
        }

        $lifetime = (int) $response->json('expires_in', 86400);

        Cache::put(
            $this->cacheKey(),
            $accessToken,
            max(60, $lifetime - self::EXPIRY_MARGIN_SECONDS),
        );

        return $accessToken;
    }

    /**
     * Drop the cached access token, so the next call fetches a fresh one.
     */
    public function forget(): void
    {
        Cache::forget($this->cacheKey());
    }

    /**
     * Keyed by client id so two accounts in one application cannot collide.
     */
    private function cacheKey(): string
    {
        return 'waitlist.constant_contact.access_token.'.sha1($this->clientId);
    }
}
