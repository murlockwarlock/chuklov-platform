<?php

namespace App\Modules\Referrals\Application;

use App\Modules\Channels\Application\NotificationChannelRegistry;
use App\Modules\Channels\Domain\ValueObjects\NotificationDeliveryResult;
use App\Modules\Identity\Domain\Models\Client;

final readonly class SendReferralInvite
{
    public function __construct(
        private NotificationChannelRegistry $channels,
        private BuildReferralInviteMessage $messages,
    ) {}

    public function handle(Client $client, string $recipientExternalId): NotificationDeliveryResult
    {
        $channel = $this->channels->get('telegram');

        if ($channel === null || ! $channel->capabilities()->supportsProactiveDelivery) {
            return NotificationDeliveryResult::unavailable('telegram_channel_unavailable');
        }

        return $channel->send($this->messages->handle($client, $recipientExternalId));
    }
}
