<?php

namespace App\Modules\Broadcasts\Application;

use App\Models\User;
use App\Modules\Broadcasts\Domain\Enums\BroadcastCampaignState;
use App\Modules\Broadcasts\Domain\Enums\BroadcastRecipientState;
use App\Modules\Broadcasts\Domain\Models\BroadcastAudienceSnapshot;
use App\Modules\Broadcasts\Domain\Models\BroadcastCampaign;
use App\Modules\Broadcasts\Domain\Models\BroadcastRecipient;
use App\Modules\Channels\Domain\Enums\NotificationMessageMode;
use App\Modules\Identity\Domain\Enums\ChannelIdentityStatus;
use App\Modules\Identity\Domain\Enums\ConsentSubject;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Security\Application\RecordAuditEvent;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final readonly class TestBroadcastCampaign
{
    public function __construct(private BroadcastAuthorization $authorization, private BroadcastEligibilityPolicy $eligibility, private ProcessBroadcastBatch $delivery, private RecordAuditEvent $audit, private BroadcastCampaignMedia $media) {}

    /** @return Collection<int, Client> */
    public function eligibleTestClients(User $actor, BroadcastCampaign $campaign): Collection
    {
        $organization = $this->authorization->manage($actor);
        if ((int) $campaign->organization_id !== $organization->getKey()) {
            throw new AuthorizationException('The campaign is outside the current organization.');
        }

        return Client::query()
            ->where('organization_id', $organization->getKey())
            ->whereHas('channelIdentities', function (EloquentBuilder $query): void {
                $query
                    ->whereColumn('client_channel_identities.organization_id', 'clients.organization_id')
                    ->where('channel', 'telegram')
                    ->where('verification_status', ChannelIdentityStatus::Verified->value);
            })
            ->whereExists(function (QueryBuilder $query): void {
                $query
                    ->selectRaw('1')
                    ->from('client_consents as marketing_consent')
                    ->whereColumn('marketing_consent.organization_id', 'clients.organization_id')
                    ->whereColumn('marketing_consent.client_id', 'clients.id')
                    ->where('marketing_consent.subject', ConsentSubject::Marketing->value)
                    ->where('marketing_consent.granted', true)
                    ->whereNotExists(function (QueryBuilder $query): void {
                        $query
                            ->selectRaw('1')
                            ->from('client_consents as newer_consent')
                            ->whereColumn('newer_consent.organization_id', 'marketing_consent.organization_id')
                            ->whereColumn('newer_consent.client_id', 'marketing_consent.client_id')
                            ->where('newer_consent.subject', ConsentSubject::Marketing->value)
                            ->where(function (QueryBuilder $query): void {
                                $query
                                    ->whereColumn('newer_consent.recorded_at', '>', 'marketing_consent.recorded_at')
                                    ->orWhere(function (QueryBuilder $query): void {
                                        $query
                                            ->whereColumn('newer_consent.recorded_at', 'marketing_consent.recorded_at')
                                            ->whereColumn('newer_consent.id', '>', 'marketing_consent.id');
                                    });
                            });
                    });
            })
            ->orderBy('full_name')
            ->limit(200)
            ->get();
    }

    public function handle(User $actor, BroadcastCampaign $campaign, int $testClientId): BroadcastRecipient
    {
        $organization = $this->authorization->manage($actor);
        if ((int) $campaign->organization_id !== $organization->getKey()) {
            throw new AuthorizationException('The campaign is outside the current organization.');
        }
        $recipient = DB::transaction(function () use ($campaign, $testClientId, $organization): BroadcastRecipient {
            $locked = BroadcastCampaign::query()->where('organization_id', $organization->getKey())->whereKey($campaign->getKey())->lockForUpdate()->firstOrFail();
            if ($locked->state !== BroadcastCampaignState::Draft) {
                throw ValidationException::withMessages(['test_client_id' => 'Тестовая отправка доступна только для черновика.']);
            }
            $deliveryMode = NotificationMessageMode::tryFrom((string) $locked->delivery_mode);
            if (! $deliveryMode instanceof NotificationMessageMode) {
                throw ValidationException::withMessages(['campaign' => 'Формат сообщения рассылки недоступен.']);
            }
            $this->media->ensureAvailable($deliveryMode, is_array($locked->media) ? $locked->media : null);
            $client = Client::query()->where('organization_id', $organization->getKey())->whereKey($testClientId)->first();
            if ($client === null) {
                throw ValidationException::withMessages(['test_client_id' => 'Выберите клиента текущей организации.']);
            }
            $eligible = $this->eligibility->evaluate($client, $organization->getKey(), $locked->channel_priority);
            if (! $eligible['eligible']) {
                $message = match ($eligible['reason']) {
                    'marketing_consent_missing' => 'У тестового получателя нет согласия на маркетинговые сообщения.',
                    'marketing_suppressed' => 'Согласие тестового получателя на маркетинговые сообщения отозвано.',
                    'verified_channel_unavailable' => 'У тестового получателя нет подтверждённого Telegram.',
                    default => 'Тестовый получатель недоступен для этой рассылки.',
                };

                throw ValidationException::withMessages(['test_client_id' => $message]);
            }
            $version = (int) BroadcastAudienceSnapshot::query()->where('organization_id', $organization->getKey())->where('campaign_id', $locked->getKey())->max('version') + 1;
            $snapshot = BroadcastAudienceSnapshot::query()->create(['organization_id' => $organization->getKey(), 'campaign_id' => $locked->getKey(), 'version' => $version, 'draft_version' => $locked->draft_version, 'segment_definition' => [], 'segment_summary' => 'Тестовая отправка выбранному получателю', 'channel_priority' => $locked->channel_priority, 'delivery_mode' => $locked->delivery_mode, 'caption_position' => $locked->caption_position, 'media' => $locked->media, 'template_version_ru_id' => $locked->template_version_ru_id, 'template_version_en_id' => $locked->template_version_en_id, 'matched_count' => 1, 'eligible_count' => 1, 'suppressed_count' => 0, 'materialized_at' => now()]);

            return BroadcastRecipient::query()->create(['organization_id' => $organization->getKey(), 'campaign_id' => $locked->getKey(), 'snapshot_id' => $snapshot->getKey(), 'client_id' => $client->getKey(), 'kind' => 'test', 'language' => $client->language ?: 'ru', 'channel' => $eligible['channel'], 'external_id' => $eligible['external_id'], 'render_context' => ['client' => ['full_name' => $client->full_name, 'language' => $client->language ?: 'ru']], 'state' => BroadcastRecipientState::Pending, 'idempotency_key' => hash('sha256', $organization->getKey().'|broadcast-test|'.$locked->getKey().'|'.$snapshot->getKey().'|'.$client->getKey())]);
        });

        $recipient = $this->delivery->deliverTest($recipient);
        $reason = $recipient->last_error_code ?: $recipient->exclusion_code;
        $action = $recipient->state === BroadcastRecipientState::Delivered
            ? 'broadcast.campaign.test_sent'
            : ($reason === 'delivery_outcome_unknown' ? 'broadcast.campaign.test_unknown' : 'broadcast.campaign.test_failed');
        $this->audit->handle($organization, $actor, $action, BroadcastCampaign::class, (string) $campaign->getKey(), ['test_recipient_id' => $recipient->getKey(), 'channel' => $recipient->channel, 'reason' => $reason]);

        return $recipient->refresh();
    }
}
