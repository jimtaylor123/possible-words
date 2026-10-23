<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class AccountSuspensionMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public User $user, public bool $suspended) {}

    public function build(): self
    {
        return $this->subject($this->suspended ? 'Your PossibleWords account is unavailable' : 'Your PossibleWords account has been restored')
            ->view('emails.account-suspension');
    }
}
