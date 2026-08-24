<?php

namespace App\Modules\Identity\Application;

class VerifiedChannelIdentity
{
    public function __construct(
        public readonly string $channel,
        public readonly string $externalId,
        public readonly string $displayName,
        public readonly string $language,
        public readonly ?string $startParameter = null,
    ) {}
}
