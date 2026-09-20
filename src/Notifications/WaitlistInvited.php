<?php

declare(strict_types=1);

namespace OffloadProject\Waitlist\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use OffloadProject\Waitlist\Models\WaitlistEntry;

final class WaitlistInvited extends Notification
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
        return (new MailMessage)
            ->subject(__('waitlist::notifications.invited.subject'))
            ->markdown('waitlist::mail.invited', [
                'name' => $this->entry->name,
                'url' => url('/'),
                'entry' => $this->entry,
            ]);
    }
}
