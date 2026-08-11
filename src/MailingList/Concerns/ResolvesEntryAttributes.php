<?php

declare(strict_types=1);

namespace OffloadProject\Waitlist\MailingList\Concerns;

use Illuminate\Support\Str;
use OffloadProject\Waitlist\Models\WaitlistEntry;

trait ResolvesEntryAttributes
{
    private function firstName(WaitlistEntry $entry): string
    {
        return Str::before($entry->name, ' ');
    }

    private function lastName(WaitlistEntry $entry): string
    {
        return str_contains($entry->name, ' ') ? Str::after($entry->name, ' ') : '';
    }

    /**
     * Merge the configured attribute mapper with any per-call attributes.
     *
     * @param  array{attributes?: array<string, mixed>}  $options
     * @return array<string, mixed>
     */
    private function attributes(WaitlistEntry $entry, array $options): array
    {
        /** @var callable|null $mapper */
        $mapper = config('waitlist.mailing_list.attributes');

        $mapped = $mapper !== null ? $mapper($entry) : [];

        return array_merge($mapped, $options['attributes'] ?? []);
    }

    /**
     * @param  array{tags?: array<array-key, string>}  $options
     * @return list<string>
     */
    private function tags(array $options): array
    {
        /** @var array<array-key, string> $tags */
        $tags = $options['tags'] ?? config('waitlist.mailing_list.tags', []);

        return array_values($tags);
    }

    /**
     * @param  array{double_optin?: bool}  $options
     */
    private function wantsDoubleOptIn(array $options): bool
    {
        return (bool) ($options['double_optin'] ?? config('waitlist.mailing_list.double_optin', false));
    }
}
