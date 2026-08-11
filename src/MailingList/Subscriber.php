<?php

declare(strict_types=1);

namespace OffloadProject\Waitlist\MailingList;

/**
 * A subscriber as it exists on the remote mailing list service.
 */
final readonly class Subscriber
{
    /**
     * @param  array<string, mixed>  $raw  The untouched payload returned by the provider.
     */
    public function __construct(
        public string $id,
        public string $email,
        public ?string $status = null,
        public array $raw = [],
    ) {}
}
