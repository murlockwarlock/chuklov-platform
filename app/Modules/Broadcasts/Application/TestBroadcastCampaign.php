<?php

namespace App\Modules\Broadcasts\Application;

use App\Models\User;
use App\Modules\Broadcasts\Domain\Enums\BroadcastCampaignState;
use App\Modules\Broadcasts\Domain\Enums\BroadcastRecipientState;
use App\Modules\Broadcasts\Domain\Models\BroadcastAudienceSnapshot;
use App\Modules\Broadcasts\Domain\Models\BroadcastCampaign;
use App\Modules\Broadcasts\Domain\Models\BroadcastRecipient;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Security\Application\RecordAuditEvent;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final readonly class TestBroadcastCampaign
{
    public function __construct(private BroadcastAuthorization $authorization, private BroadcastEligibilityPolicy $eligibility, private ProcessBroadcastBatch $delivery, private RecordAuditEvent $audit) {}

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
            $client = Client::query()->where('organization_id', $organization->getKey())->whereKey($testClientId)->first();
            if ($client === null) {
                throw ValidationException::withMessages(['test_client_id' => 'Выберите клиента текущей организации.']);
            }
            $eligible = $this->eligibility->evaluate($client, $organization->getKey(), $locked->channel_priority);
            if (! $eligible['eligible']) {
                throw ValidationException::withMessages(['test_client_id' => 'У тестового получателя нет применимого согласия и подтверждённого канала.']);
            }
            $version = (int) BroadcastAudienceSnapshot::query()->where('organization_id', $organization->getKey())->where('campaign_id', $locked->getKey())->max('version') + 1;
            $snapshot = BroadcastAudienceSnapshot::query()->create(['organization_id' => $organization->getKey(), 'campaign_id' => $locked->getKey(), 'version' => $version, 'draft_version' => $locked->draft_version, 'segment_definition' => [], 'segment_summary' => 'Тестовая отправка выбранному получателю', 'channel_priority' => $locked->channel_priority, 'template_version_ru_id' => $locked->template_version_ru_id, 'template_version_en_id' => $locked->template_version_en_id, 'matched_count' => 1, 'eligible_count' => 1, 'suppressed_count' => 0, 'materialized_at' => now()]);

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
