<?php

declare(strict_types=1);

namespace OffloadProject\Waitlist\Exceptions;

use Exception;

final class MailingListException extends Exception
{
    public static function driverNotSupported(string $driver): self
    {
        return new self("Mailing list driver [{$driver}] is not supported.");
    }

    public static function missingCredentials(string $driver, string $key): self
    {
        return new self("Mailing list driver [{$driver}] is missing the [{$key}] credential. Set it in config/waitlist.php.");
    }

    public static function missingListId(string $driver): self
    {
        return new self("No mailing list id configured for driver [{$driver}]. Connect the waitlist with Waitlist::for(\$slug)->connectMailingList(\$listId) or set a default list id in config/waitlist.php.");
    }

    public static function requestFailed(string $driver, string $message, int $status): self
    {
        return new self("Mailing list request to [{$driver}] failed with status {$status}: {$message}");
    }
}
