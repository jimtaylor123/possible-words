<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class NotificationMail extends Mailable
{
    use Queueable, SerializesModels;

    /** @param list<array<string, mixed>> $notifications */
    public function __construct(public array $notifications, public bool $digest = false) {}

    public function build(): static
    {
        return $this->subject($this->digest ? 'Your Possible Words notification digest' : 'New Possible Words notification')
            ->view('mail.notifications');
    }
}
