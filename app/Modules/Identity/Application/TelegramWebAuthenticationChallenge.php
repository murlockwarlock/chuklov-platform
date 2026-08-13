<?php

namespace App\Modules\Identity\Application;

use App\Modules\Identity\Domain\Models\ClientTelegramAuthenticationRequest;

final readonly class TelegramWebAuthenticationChallenge
{
    public function __construct(
        public ClientTelegramAuthenticationRequest $request,
        public string $url,
    ) {}
}
