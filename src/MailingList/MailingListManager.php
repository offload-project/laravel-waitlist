<?php

declare(strict_types=1);

namespace OffloadProject\Waitlist\MailingList;

use Closure;
use Illuminate\Support\Facades\Config;
use OffloadProject\Waitlist\Contracts\MailingListDriver;
use OffloadProject\Waitlist\Exceptions\MailingListException;
use OffloadProject\Waitlist\MailingList\ConstantContact\AccessToken;
use OffloadProject\Waitlist\MailingList\ConstantContact\CacheRefreshTokenStore;
use OffloadProject\Waitlist\MailingList\ConstantContact\RefreshTokenStore;
use OffloadProject\Waitlist\MailingList\Drivers\ArrayDriver;
use OffloadProject\Waitlist\MailingList\Drivers\AudiencefulDriver;
use OffloadProject\Waitlist\MailingList\Drivers\ConstantContactDriver;
use OffloadProject\Waitlist\MailingList\Drivers\KitDriver;
use OffloadProject\Waitlist\MailingList\Drivers\LogDriver;
use OffloadProject\Waitlist\MailingList\Drivers\MailchimpDriver;
use OffloadProject\Waitlist\Models\Waitlist;
use OffloadProject\Waitlist\Models\WaitlistEntry;

final class MailingListManager
{
    /** @var array<string, MailingListDriver> */
    private array $drivers = [];

    /** @var array<string, Closure(array<string, mixed>): MailingListDriver> */
    private array $customCreators = [];

    public function enabled(): bool
    {
        return (bool) config('waitlist.mailing_list.enabled', false);
    }

    public function getDefaultDriver(): string
    {
        return (string) config('waitlist.mailing_list.default', 'log');
    }

    public function driver(?string $name = null): MailingListDriver
    {
        $name ??= $this->getDefaultDriver();

        return $this->drivers[$name] ??= $this->resolve($name);
    }

    /**
     * Resolve the driver a given waitlist should sync to.
     */
    public function for(?Waitlist $waitlist = null): MailingListDriver
    {
        return $this->driver($this->driverNameFor($waitlist));
    }

    public function driverNameFor(?Waitlist $waitlist = null): string
    {
        return $waitlist?->mailingListDriver() ?? $this->getDefaultDriver();
    }

    /**
     * The list a waitlist syncs into, falling back to the driver's default.
     */
    public function listIdFor(?Waitlist $waitlist = null): ?string
    {
        $listId = $waitlist?->mailingListId();

        if (filled($listId)) {
            return $listId;
        }

        $fallback = config("waitlist.mailing_list.drivers.{$this->driverNameFor($waitlist)}.list_id");

        return filled($fallback) ? (string) $fallback : null;
    }

    /**
     * Tag an entry on whichever list its waitlist is connected to.
     *
     * @param  list<string>  $tags
     */
    public function tagEntry(WaitlistEntry $entry, array $tags): void
    {
        $waitlist = $entry->waitlist;
        $listId = $this->listIdFor($waitlist);

        if ($listId === null) {
            return;
        }

        $this->for($waitlist)->tag($entry, $listId, $tags);
    }

    /**
     * Register a driver of your own.
     *
     * @param  Closure(array<string, mixed>): MailingListDriver  $callback
     */
    public function extend(string $name, Closure $callback): self
    {
        $this->customCreators[$name] = $callback;

        unset($this->drivers[$name]);

        return $this;
    }

    /**
     * Drop resolved drivers so they pick up configuration changes.
     */
    public function forget(?string $name = null): self
    {
        if ($name === null) {
            $this->drivers = [];

            return $this;
        }

        unset($this->drivers[$name]);

        return $this;
    }

    /**
     * Swap in the in-memory driver for tests, and run syncs inline so
     * assertions can run straight after the call under test.
     */
    public function fake(?string $listId = null): ArrayDriver
    {
        Config::set('waitlist.mailing_list.enabled', true);
        Config::set('waitlist.mailing_list.default', 'array');
        Config::set('waitlist.mailing_list.queue.enabled', false);

        if ($listId !== null || blank(config('waitlist.mailing_list.drivers.array.list_id'))) {
            Config::set('waitlist.mailing_list.drivers.array.list_id', $listId ?? 'fake-list');
        }

        return $this->drivers['array'] = new ArrayDriver();
    }

    private function resolve(string $name): MailingListDriver
    {
        /** @var array<string, mixed> $config */
        $config = config("waitlist.mailing_list.drivers.{$name}", []);

        if (isset($this->customCreators[$name])) {
            return ($this->customCreators[$name])($config);
        }

        return match ($name) {
            'mailchimp' => $this->createMailchimpDriver($config),
            'kit' => $this->createKitDriver($config),
            'audienceful' => $this->createAudiencefulDriver($config),
            'constant_contact' => $this->createConstantContactDriver($config),
            'log' => new LogDriver(isset($config['channel']) ? (string) $config['channel'] : null),
            'array' => new ArrayDriver(),
            default => throw MailingListException::driverNotSupported($name),
        };
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function createMailchimpDriver(array $config): MailchimpDriver
    {
        if (blank($config['key'] ?? null)) {
            throw MailingListException::missingCredentials('mailchimp', 'key');
        }

        return new MailchimpDriver(
            key: (string) $config['key'],
            server: filled($config['server'] ?? null) ? (string) $config['server'] : null,
            timeout: $this->timeout($config),
            retries: $this->retries($config),
        );
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function createKitDriver(array $config): KitDriver
    {
        if (blank($config['key'] ?? null)) {
            throw MailingListException::missingCredentials('kit', 'key');
        }

        return new KitDriver(
            key: (string) $config['key'],
            listType: (string) ($config['list_type'] ?? 'form'),
            timeout: $this->timeout($config),
            retries: $this->retries($config),
        );
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function createAudiencefulDriver(array $config): AudiencefulDriver
    {
        if (blank($config['key'] ?? null)) {
            throw MailingListException::missingCredentials('audienceful', 'key');
        }

        return new AudiencefulDriver(
            key: (string) $config['key'],
            listType: (string) ($config['list_type'] ?? 'publication'),
            timeout: $this->timeout($config),
            retries: $this->retries($config),
        );
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function createConstantContactDriver(array $config): ConstantContactDriver
    {
        foreach (['client_id', 'client_secret'] as $required) {
            if (blank($config[$required] ?? null)) {
                throw MailingListException::missingCredentials('constant_contact', $required);
            }
        }

        /*
         * The store is injectable because the refresh token rotates on every
         * exchange: an application that cannot afford to lose it to a cache
         * flush passes its own, backed by a table.
         */
        $store = $config['refresh_token_store'] ?? null;

        return new ConstantContactDriver(
            token: new AccessToken(
                clientId: (string) $config['client_id'],
                clientSecret: (string) $config['client_secret'],
                store: $store instanceof RefreshTokenStore
                    ? $store
                    : new CacheRefreshTokenStore(filled($config['refresh_token'] ?? null) ? (string) $config['refresh_token'] : null),
                timeout: $this->timeout($config),
            ),
            timeout: $this->timeout($config),
            retries: $this->retries($config),
        );
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function timeout(array $config): int
    {
        return (int) ($config['timeout'] ?? config('waitlist.mailing_list.timeout', 10));
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function retries(array $config): int
    {
        return (int) ($config['retries'] ?? config('waitlist.mailing_list.retries', 2));
    }
}
