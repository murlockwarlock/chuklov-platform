<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

final class ClientEmailVerificationCode extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(private readonly string $code) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Your client portal verification code');
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.client-email-verification-code',
            with: ['code' => $this->code],
        );
    }

    /** @return list<never> */
    public function attachments(): array
    {
        return [];
    }
}
