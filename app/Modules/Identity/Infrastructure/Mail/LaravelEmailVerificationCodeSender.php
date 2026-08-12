<?php

namespace App\Modules\Identity\Infrastructure\Mail;

use App\Mail\ClientEmailVerificationCode;
use App\Modules\Identity\Domain\Contracts\EmailVerificationCodeSender;
use Illuminate\Contracts\Mail\Mailer;

final class LaravelEmailVerificationCodeSender implements EmailVerificationCodeSender
{
    public function __construct(private readonly Mailer $mailer) {}

    public function send(string $email, string $code): void
    {
        $this->mailer->to($email)->send(new ClientEmailVerificationCode($code));
    }
}
