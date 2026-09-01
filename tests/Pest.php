<?php

declare(strict_types=1);

use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use OffloadProject\Waitlist\Tests\TestCase;

uses(TestCase::class)->in('Feature');

/**
 * Collects the messages written to the log from here to the end of the test.
 *
 * Mocking the Log facade would be shorter, but Laravel reports PHP deprecation
 * notices through that same facade, and dependencies emit them on some of the
 * PHP and package versions this suite runs against. A mock that only expects
 * info() dies on the channel() call the reporter makes, which turns somebody
 * else's deprecation into a failure here. Listening for the event the real
 * logger fires keeps the two apart.
 *
 * @return ArrayObject<int, string>
 */
function capturedLog(): ArrayObject
{
    /** @var ArrayObject<int, string> $messages */
    $messages = new ArrayObject;

    Event::listen(MessageLogged::class, function (MessageLogged $logged) use ($messages): void {
        $messages[] = $logged->message;
    });

    return $messages;
}

/*
 * Asserts a captured log holds exactly one message mentioning the given text.
 */
expect()->extend('toHaveLoggedOnce', function (string $text) {
    $matching = array_filter(
        (array) $this->value,
        fn (string $message): bool => str_contains($message, $text),
    );

    expect($matching)->toHaveCount(1);

    return $this;
});
