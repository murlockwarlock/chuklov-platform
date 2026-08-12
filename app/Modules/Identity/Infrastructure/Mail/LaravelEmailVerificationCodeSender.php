<?php

namespace App\Modules\Identity\Infrastructure\Mail;

use App\Mail\ClientEmailVerificationCode;
use App\Modules\Identity\Domain\Contracts\EmailVerificationCodeSender;
use Illuminate\Contracts\Mail\Factory as MailFactory;
use Illuminate\Mail\Mailer;
use LogicException;
use Symfony\Component\Mailer\Transport\TransportInterface;

final class LaravelEmailVerificationCodeSender implements EmailVerificationCodeSender
{
    public function __construct(private readonly MailFactory $mailFactory) {}

    public function send(string $email, string $code): void
    {
        $mailer = $this->mailFactory->mailer((string) config(
            'mail.auth_mailer',
            config('mail.default'),
        ));

        if (! $mailer instanceof Mailer) {
            throw new LogicException('The authentication mail transport cannot be verified.');
        }

        $transport = $mailer->getSymfonyTransport();

        if ($this->isLoggingTransport($transport)) {
            throw new LogicException('The authentication mail transport cannot log message contents.');
        }

        $mailer->to($email)->send(new ClientEmailVerificationCode($code));
    }

    private function isLoggingTransport(TransportInterface $transport): bool
    {
        return str_contains(strtolower((string) $transport), 'log');
    }
}
