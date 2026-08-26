<?php

namespace App\Modules\Broadcasts\Application;

use App\Modules\Channels\Application\NotificationChannelRegistry;
use App\Modules\Identity\Domain\Enums\ChannelIdentityStatus;
use App\Modules\Identity\Domain\Enums\ConsentSubject;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Identity\Domain\Models\ClientChannelIdentity;
use App\Modules\Identity\Domain\Models\ClientConsent;

final readonly class BroadcastEligibilityPolicy
{
    public function __construct(private NotificationChannelRegistry $channels) {}

    /**
     * @param  list<string>  $channelPriority
     * @return array{eligible: bool, reason: string|null, channel: string|null, external_id: string|null}
     */
    public function evaluate(Client $client, int $organizationId, array $channelPriority): array
    {
        if ((int) $client->organization_id !== $organizationId) {
            return ['eligible' => false, 'reason' => 'organization_mismatch', 'channel' => null, 'external_id' => null];
        }

        $consent = ClientConsent::query()->where('organization_id', $organizationId)->where('client_id', $client->getKey())->where('subject', ConsentSubject::Marketing->value)->latest('recorded_at')->value('granted');

        if ($consent !== true && $consent !== 1) {
            return ['eligible' => false, 'reason' => $consent === null ? 'marketing_consent_missing' : 'marketing_suppressed', 'channel' => null, 'external_id' => null];
        }

        foreach ($channelPriority as $channelName) {
            $channel = $this->channels->get($channelName);
            if ($channel === null || ! $channel->capabilities()->supportsProactiveDelivery) {
                continue;
            }
            $identity = ClientChannelIdentity::query()->where('organization_id', $organizationId)->where('client_id', $client->getKey())->where('channel', $channelName)->where('verification_status', ChannelIdentityStatus::Verified->value)->orderBy('id')->first();
            if ($identity !== null) {
                return ['eligible' => true, 'reason' => null, 'channel' => $channelName, 'external_id' => (string) $identity->external_id];
            }
        }

        return ['eligible' => false, 'reason' => 'verified_channel_unavailable', 'channel' => null, 'external_id' => null];
    }
}
