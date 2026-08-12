<?php

namespace Tests\Support;

use App\Modules\Identity\Domain\Contracts\EmailVerificationCodeSender;

final class RecordingEmailVerificationCodeSender implements EmailVerificationCodeSender
{
    public ?string $email = null;

    public ?string $code = null;

    public function send(string $email, string $code): void
    {
        $this->email = $email;
        $this->code = $code;
    }
}
