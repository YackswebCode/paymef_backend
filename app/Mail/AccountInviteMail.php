<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Password;

class AccountInviteMail extends Mailable
{
    use Queueable, SerializesModels;

    public string $url;

    public function __construct(
        public User $user,
        public string $roleLabel
    ) {
        // Reuses the password broker token so the invite expires like a reset
        // link and can only ever set a password once.
        $token = Password::broker()->createToken($user);

        $this->url = rtrim((string) config('paymef.frontend_url'), '/')
            .'/reset-password?token='.$token
            .'&email='.urlencode($user->email);
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'You have been invited to PayMef');
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.account-invite',
            with: [
                'user' => $this->user,
                'roleLabel' => $this->roleLabel,
                'url' => $this->url,
            ],
        );
    }
}
