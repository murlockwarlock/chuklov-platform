<?php

namespace App\Modules\Channels\Domain\Contracts;

use App\Modules\Channels\Domain\ValueObjects\ChannelCapabilities;
use App\Modules\Channels\Domain\ValueObjects\CompanionOutboundChunk;
use App\Modules\Channels\Domain\ValueObjects\NotificationDeliveryResult;

interface MessagingChannel
{
    public function name(): string;

    public function capabilities(): ChannelCapabilities;

    public function sendCompanionChunk(CompanionOutboundChunk $chunk): NotificationDeliveryResult;

    public function sendTyping(string $recipientExternalId): bool;
}
