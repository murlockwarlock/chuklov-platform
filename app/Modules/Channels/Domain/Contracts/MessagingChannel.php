<?php

namespace App\Modules\Channels\Domain\Contracts;

use App\Modules\Channels\Domain\ValueObjects\ChannelCapabilities;

interface MessagingChannel
{
    public function name(): string;

    public function capabilities(): ChannelCapabilities;
}
