<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Notification;
use Illuminate\Support\ServiceProvider;
use OffloadProject\Waitlist\Facades\Waitlist;
use OffloadProject\Waitlist\Notifications\VerifyWaitlistEmail;
use OffloadProject\Waitlist\Notifications\WaitlistInvited;
use OffloadProject\Waitlist\WaitlistServiceProvider;

/*
 * Both emails render views now, and their words live in translations rather
 * than inside the notifications — which matters more here than it might
 * elsewhere, because both classes are `final`. An application could not
 * subclass its way to a different greeting; the only route was replacing the
 * class through config.
 *
 * Rendered rather than faked: the rest of the suite asserts a notification was
 * sent, which passes just as happily when the view behind it is missing or
 * names an undefined variable.
 */

beforeEach(function (): void {
    Notification::fake();
    Waitlist::create('Beta Program', 'beta');
});

it('renders the verification email', function (): void {
    // The token only exists when verification is on, and the link needs it.
    config(['waitlist.verification.enabled' => true]);

    $entry = Waitlist::for('beta')->add('Ada Lovelace', 'ada@example.com');

    $mail = (new VerifyWaitlistEmail($entry))->toMail($entry);
    $html = (string) $mail->render();

    expect($mail->subject)->toBe(__('waitlist::notifications.verify.subject'))
        // The name is interpolated, and the token has to survive into the link.
        ->and($html)->toContain('Ada Lovelace')
        ->and($html)->toContain($entry->verification_token)
        ->and($html)->toContain(__('waitlist::notifications.verify.action_text'))
        ->and($html)->not->toContain('waitlist::');
});

it('renders the invitation email', function (): void {
    $entry = Waitlist::for('beta')->add('Grace Hopper', 'grace@example.com');

    $mail = (new WaitlistInvited($entry))->toMail($entry);
    $html = (string) $mail->render();

    expect($mail->subject)->toBe(__('waitlist::notifications.invited.subject'))
        ->and($html)->toContain('Grace Hopper')
        ->and($html)->toContain(__('waitlist::notifications.invited.action_text'))
        ->and($html)->not->toContain('waitlist::');
});

/*
 * The point of the exercise: an application can take both over.
 */
it('offers the views and the copy for publishing', function (): void {
    expect(ServiceProvider::pathsToPublish(WaitlistServiceProvider::class, 'waitlist-views'))->not->toBeEmpty()
        ->and(ServiceProvider::pathsToPublish(WaitlistServiceProvider::class, 'waitlist-lang'))->not->toBeEmpty()
        ->and(view()->exists('waitlist::mail.verify'))->toBeTrue()
        ->and(view()->exists('waitlist::mail.invited'))->toBeTrue();
});
