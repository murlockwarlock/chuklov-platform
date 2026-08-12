<?php

namespace App\Modules\Identity\Domain\Contracts;

interface EmailVerificationCodeSender
{
    public function send(string $email, string $code): void;
}
