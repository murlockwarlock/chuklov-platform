<?php

namespace App\Modules\Broadcasts\Application;

use App\Models\User;
use App\Modules\Broadcasts\Domain\Enums\BroadcastCampaignState;
use App\Modules\Broadcasts\Domain\Enums\BroadcastRecipientState;
use App\Modules\Broadcasts\Domain\Models\BroadcastBatch;
use App\Modules\Broadcasts\Domain\Models\BroadcastCampaign;
use App\Modules\Broadcasts\Domain\Models\BroadcastDeliveryAttempt;
use App\Modules\Broadcasts\Domain\Models\BroadcastRecipient;
use App\Modules\Channels\Domain\Enums\NotificationDeliveryOutcome;
use App\Modules\Security\Application\RecordAuditEvent;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final readonly class CancelBroadcastCampaign
{
    public function __construct(private BroadcastAuthorization $authorization, private RecordAuditEvent $audit) {}

    public function handle(User $actor, BroadcastCampaign $campaign): BroadcastCampaign
    {
        $organization = $this->authorization->manage($actor);
        if ((int) $campaign->organization_id !== $organization->getKey()) {
            throw new AuthorizationException('The campaign is outside the current organization.');
        }

        DB::transaction(function () use ($actor, $campaign, $organization): void {
            $locked = BroadcastCampaign::query()->where('organization_id', $organization->getKey())->whereKey($campaign->getKey())->lockForUpdate()->firstOrFail();
            if (! in_array($locked->state, [BroadcastCampaignState::Draft, BroadcastCampaignState::Scheduled], true)) {
                throw ValidationException::withMessages(['campaign' => 'Эту рассылку уже нельзя отменить.']);
            }
            $this->closeUnfinishedWork($locked);
            $locked->forceFill(['state' => BroadcastCampaignState::Cancelled, 'cancelled_at' => now()])->save();
            $this->audit->handle($organization, $actor, 'broadcast.campaign.cancelled', BroadcastCampaign::class, (string) $locked->getKey());
        });

        return $campaign->refresh();
    }

    private function closeUnfinishedWork(BroadcastCampaign $campaign): void
    {
        $batches = BroadcastBatch::query()
            ->where('organization_id', $campaign->organization_id)
            ->where('campaign_id', $campaign->getKey())
            ->whereIn('state', ['pending', 'claimed'])
            ->lockForUpdate()
            ->get();

        foreach ($batches as $batch) {
            $this->closeRecipients($campaign, $batch->getKey());
            $batch->forceFill([
                'state' => 'failed',
                'lease_token' => null,
                'claimed_at' => null,
                'available_at' => null,
                'last_dispatch_error_code' => 'campaign_cancelled',
                'completed_at' => now(),
            ])->save();
        }

        $this->closeRecipients($campaign, null);

        $recipients = BroadcastRecipient::query()
            ->where('organization_id', $campaign->organization_id)
            ->where('campaign_id', $campaign->getKey())
            ->where('snapshot_id', $campaign->audience_snapshot_id)
            ->where('kind', 'production');
        $campaign->forceFill([
            'sent_count' => (clone $recipients)->where('attempt_count', '>', 0)->count(),
            'delivered_count' => (clone $recipients)->where('state', BroadcastRecipientState::Delivered->value)->count(),
            'failed_count' => (clone $recipients)->where('state', BroadcastRecipientState::Failed->value)->count(),
            'suppressed_count' => (clone $recipients)->where('state', BroadcastRecipientState::Suppressed->value)->count(),
        ])->save();
    }

    private function closeRecipients(BroadcastCampaign $campaign, ?int $batchId): void
    {
        $recipients = BroadcastRecipient::query()
            ->where('organization_id', $campaign->organization_id)
            ->where('campaign_id', $campaign->getKey())
            ->when($batchId === null, fn ($query) => $query->whereNull('batch_id'))
            ->when($batchId !== null, fn ($query) => $query->where('batch_id', $batchId))
            ->whereIn('state', [BroadcastRecipientState::Pending->value, BroadcastRecipientState::Claimed->value])
            ->lockForUpdate()
            ->get();

        foreach ($recipients as $recipient) {
            $attempt = $recipient->state === BroadcastRecipientState::Claimed
                ? BroadcastDeliveryAttempt::query()
                    ->where('organization_id', $campaign->organization_id)
                    ->where('recipient_id', $recipient->getKey())
                    ->where('attempt_number', $recipient->attempt_count)
                    ->lockForUpdate()
                    ->first()
                : null;

            if ($attempt?->outcome === NotificationDeliveryOutcome::InFlight) {
                $attempt->forceFill([
                    'outcome' => NotificationDeliveryOutcome::Unknown,
                    'error_code' => 'delivery_outcome_unknown',
                    'completed_at' => now(),
                ])->save();
                $recipient->forceFill([
                    'state' => BroadcastRecipientState::Failed,
                    'lease_token' => null,
                    'claimed_at' => null,
                    'next_attempt_at' => null,
                    'last_error_code' => 'delivery_outcome_unknown',
                    'render_context' => [],
                ])->save();

                continue;
            }

            if ($attempt?->outcome === NotificationDeliveryOutcome::Delivered) {
                $recipient->forceFill([
                    'state' => BroadcastRecipientState::Delivered,
                    'lease_token' => null,
                    'claimed_at' => null,
                    'next_attempt_at' => null,
                    'delivered_at' => $attempt->completed_at ?? now(),
                    'last_error_code' => null,
                    'provider_reference' => $attempt->provider_reference,
                    'render_context' => [],
                ])->save();

                continue;
            }

            if ($attempt?->outcome === NotificationDeliveryOutcome::Suppressed) {
                $recipient->forceFill([
                    'state' => BroadcastRecipientState::Suppressed,
                    'lease_token' => null,
                    'claimed_at' => null,
                    'next_attempt_at' => null,
                    'exclusion_code' => $attempt->error_code,
                    'render_context' => [],
                ])->save();

                continue;
            }

            $recipient->forceFill([
                'state' => BroadcastRecipientState::Failed,
                'lease_token' => null,
                'claimed_at' => null,
                'next_attempt_at' => null,
                'last_error_code' => $attempt === null ? 'campaign_cancelled' : $attempt->error_code,
                'render_context' => [],
            ])->save();
        }
    }
}
