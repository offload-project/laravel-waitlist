<?php

declare(strict_types=1);

namespace OffloadProject\Waitlist\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use OffloadProject\Waitlist\Models\WaitlistEntry;

final class VerifyWaitlistEmail extends Notification
{
    use Queueable;

    public function __construct(
        public WaitlistEntry $entry
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $url = route('waitlist.verify', ['token' => $this->entry->verification_token]);

        return (new MailMessage)
            ->subject('Verify Your Email Address')
            ->greeting("Hello {$this->entry->name}!")
            ->line('Please verify your email address to confirm your spot on the waitlist.')
            ->action('Verify Email', $url)
            ->line('If you did not sign up for this waitlist, you can ignore this email.');
    }
}
