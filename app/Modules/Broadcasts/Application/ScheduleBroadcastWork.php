<?php

namespace App\Modules\Broadcasts\Application;

use App\Modules\Broadcasts\Domain\Enums\BroadcastCampaignState;
use App\Modules\Broadcasts\Domain\Enums\BroadcastRecipientState;
use App\Modules\Broadcasts\Domain\Models\BroadcastAudienceSnapshot;
use App\Modules\Broadcasts\Domain\Models\BroadcastBatch;
use App\Modules\Broadcasts\Domain\Models\BroadcastCampaign;
use App\Modules\Broadcasts\Domain\Models\BroadcastDeliveryAttempt;
use App\Modules\Broadcasts\Domain\Models\BroadcastRecipient;
use App\Modules\Broadcasts\Jobs\ProcessBroadcastBatch;
use App\Modules\Channels\Domain\Enums\NotificationDeliveryOutcome;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Security\Application\RecordAuditEvent;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class ScheduleBroadcastWork
{
    private const MAX_CAMPAIGNS_PER_RUN = 100;

    private const MAX_BATCHES_PER_CAMPAIGN = 100;

    private const MAX_BATCH_DISPATCH_ATTEMPTS = 8;

    private const MAX_RECIPIENT_ATTEMPTS = 3;

    private const LEASE_MINUTES = 5;

    public function __construct(
        private readonly BroadcastAuthorization $authorization,
        private readonly RecordAuditEvent $audit,
    ) {}

    /** @return array{campaigns: int, batches: int} */
    public function handle(): array
    {
        $campaigns = 0;
        $batches = 0;

        for ($index = 0; $index < self::MAX_CAMPAIGNS_PER_RUN; $index++) {
            $campaign = $this->claimCampaign();
            if ($campaign === null) {
                break;
            }

            $campaigns++;
            if ($campaign->state === BroadcastCampaignState::Cancelled) {
                continue;
            }

            $dispatched = $this->dispatchBatches($campaign);
            $batches += $dispatched;
            if ($dispatched === 0) {
                $this->completeWhenNoWorkRemains($campaign);
            }
        }

        return ['campaigns' => $campaigns, 'batches' => $batches];
    }

    private function claimCampaign(): ?BroadcastCampaign
    {
        return DB::transaction(function (): ?BroadcastCampaign {
            $now = now();
            $campaign = BroadcastCampaign::query()
                ->whereIn('state', [BroadcastCampaignState::Scheduled->value, BroadcastCampaignState::Dispatching->value])
                ->where(function ($query) use ($now): void {
                    $query
                        ->where(function ($scheduled) use ($now): void {
                            $scheduled
                                ->where('state', BroadcastCampaignState::Scheduled->value)
                                ->whereNotNull('scheduled_at')
                                ->where('scheduled_at', '<=', $now);
                        })
                        ->orWhere(function ($dispatching) use ($now): void {
                            $dispatching
                                ->where('state', BroadcastCampaignState::Dispatching->value)
                                ->where(fn ($due) => $due->whereNull('scheduled_at')->orWhere('scheduled_at', '<=', $now));
                        });
                })
                ->where(fn ($query) => $query->whereNull('next_dispatch_at')->orWhere('next_dispatch_at', '<=', $now))
                ->orderBy('scheduled_at')
                ->orderBy('id')
                ->lock('FOR UPDATE SKIP LOCKED')
                ->first();
            if ($campaign === null) {
                return null;
            }

            if (! $this->authorization->creatorCanExecute($campaign)) {
                $this->cancelForRevokedAuthority($campaign, $now);

                return $campaign->refresh();
            }
            if ($campaign->audience_snapshot_id === null) {
                $this->cancelForInvalidSnapshot($campaign, $now, 'snapshot_missing');

                return $campaign->refresh();
            }
            $snapshot = BroadcastAudienceSnapshot::query()
                ->where('organization_id', $campaign->organization_id)
                ->whereKey($campaign->audience_snapshot_id)
                ->first();
            if ($snapshot === null) {
                $this->cancelForInvalidSnapshot($campaign, $now, 'snapshot_missing');

                return $campaign->refresh();
            }
            if ((int) $snapshot->campaign_id !== (int) $campaign->getKey()
                || (int) $snapshot->draft_version !== (int) $campaign->draft_version) {
                $this->cancelForInvalidSnapshot($campaign, $now, 'snapshot_superseded');

                return $campaign->refresh();
            }

            $campaign->forceFill([
                'state' => BroadcastCampaignState::Dispatching,
                'dispatch_started_at' => $campaign->dispatch_started_at ?? $now,
                'dispatch_attempt_count' => $campaign->state === BroadcastCampaignState::Scheduled
                    ? $campaign->dispatch_attempt_count + 1
                    : $campaign->dispatch_attempt_count,
                'next_dispatch_at' => null,
            ])->save();

            return $campaign->refresh();
        });
    }

    private function dispatchBatches(BroadcastCampaign $campaign): int
    {
        $now = now();
        $batchIds = BroadcastBatch::query()
            ->where('organization_id', $campaign->organization_id)
            ->where('campaign_id', $campaign->getKey())
            ->where('snapshot_id', $campaign->audience_snapshot_id)
            ->where(function ($query) use ($now): void {
                $query
                    ->where('state', 'pending')
                    ->orWhere(fn ($stale) => $stale
                        ->where('state', 'claimed')
                        ->where(fn ($claim) => $claim->whereNull('claimed_at')->orWhere('claimed_at', '<=', $now->copy()->subMinutes(self::LEASE_MINUTES))));
            })
            ->where(fn ($query) => $query->whereNull('available_at')->orWhere('available_at', '<=', $now))
            ->orderBy('sequence')
            ->limit(self::MAX_BATCHES_PER_CAMPAIGN)
            ->pluck('id');

        $dispatched = 0;
        $dispatchFailed = false;
        foreach ($batchIds as $batchId) {
            if (! $this->reserveBatchDispatch($campaign, (int) $batchId)) {
                continue;
            }

            try {
                ProcessBroadcastBatch::dispatch((int) $campaign->organization_id, (int) $batchId);
                $dispatched++;
            } catch (\Throwable) {
                $dispatchFailed = true;
                $this->recordDispatchFailure($campaign, (int) $batchId);
            }
        }

        if ($batchIds->isNotEmpty() && ! $dispatchFailed) {
            BroadcastCampaign::query()
                ->where('organization_id', $campaign->organization_id)
                ->whereKey($campaign->getKey())
                ->where('state', BroadcastCampaignState::Dispatching->value)
                ->where(fn ($query) => $query->whereNull('next_dispatch_at')->orWhere('next_dispatch_at', '<=', now()))
                ->update(['next_dispatch_at' => now()->addMinute()]);
        }

        return $dispatched;
    }

    private function reserveBatchDispatch(BroadcastCampaign $campaign, int $batchId): bool
    {
        return DB::transaction(function () use ($campaign, $batchId): bool {
            $lockedCampaign = BroadcastCampaign::query()
                ->where('organization_id', $campaign->organization_id)
                ->whereKey($campaign->getKey())
                ->lockForUpdate()
                ->first();
            $batch = BroadcastBatch::query()
                ->where('organization_id', $campaign->organization_id)
                ->whereKey($batchId)
                ->lockForUpdate()
                ->first();
            if ($batch === null
                || $lockedCampaign === null
                || $lockedCampaign->state !== BroadcastCampaignState::Dispatching
                || (int) $batch->snapshot_id !== (int) $lockedCampaign->audience_snapshot_id
                || in_array($batch->state, ['completed', 'failed'], true)) {
                return false;
            }

            $now = now();
            $staleClaim = $batch->state === 'claimed'
                && ($batch->claimed_at === null || $batch->claimed_at->lessThanOrEqualTo($now->copy()->subMinutes(self::LEASE_MINUTES)));
            if ($batch->state !== 'pending' && ! $staleClaim) {
                return false;
            }
            if ($batch->available_at !== null && $batch->available_at->greaterThan($now)) {
                return false;
            }
            if ($batch->dispatch_attempt_count >= self::MAX_BATCH_DISPATCH_ATTEMPTS) {
                $this->failBatch($batch);

                return false;
            }

            $batch->forceFill([
                'dispatch_attempt_count' => $batch->dispatch_attempt_count + 1,
                'last_dispatched_at' => $now,
                'last_dispatch_error_code' => null,
                'available_at' => $now->copy()->addMinutes(5),
            ])->save();

            return true;
        });
    }

    private function recordDispatchFailure(BroadcastCampaign $campaign, int $batchId): void
    {
        DB::transaction(function () use ($campaign, $batchId): void {
            $batchReference = BroadcastBatch::query()
                ->where('organization_id', $campaign->organization_id)
                ->whereKey($batchId)
                ->first();
            if ($batchReference === null) {
                return;
            }

            $lockedCampaign = BroadcastCampaign::query()
                ->where('organization_id', $campaign->organization_id)
                ->whereKey($campaign->getKey())
                ->lockForUpdate()
                ->first();
            $batch = BroadcastBatch::query()
                ->where('organization_id', $campaign->organization_id)
                ->whereKey($batchId)
                ->lockForUpdate()
                ->first();
            if ($batch === null
                || $lockedCampaign === null
                || $lockedCampaign->state !== BroadcastCampaignState::Dispatching
                || (int) $batch->campaign_id !== (int) $lockedCampaign->getKey()
                || in_array($batch->state, ['completed', 'failed'], true)) {
                return;
            }

            if ($batch->dispatch_attempt_count >= self::MAX_BATCH_DISPATCH_ATTEMPTS) {
                $this->failBatch($batch);
            } else {
                $batch->forceFill([
                    'available_at' => now()->addMinutes(5),
                    'last_dispatch_error_code' => 'queue_dispatch_failed',
                ])->save();
            }
            $lockedCampaign->forceFill([
                'next_dispatch_at' => now()->addMinutes(5),
                'last_dispatch_error_code' => 'queue_dispatch_failed',
            ])->save();
        });
    }

    private function failBatch(BroadcastBatch $batch): void
    {
        $this->markClaimedRecipientsUnknown($batch);
        BroadcastRecipient::query()
            ->where('organization_id', $batch->organization_id)
            ->where('batch_id', $batch->getKey())
            ->where('state', BroadcastRecipientState::Pending->value)
            ->update([
                'state' => BroadcastRecipientState::Failed->value,
                'lease_token' => null,
                'claimed_at' => null,
                'next_attempt_at' => null,
                'last_error_code' => 'queue_dispatch_exhausted',
                'render_context' => [],
                'updated_at' => now(),
            ]);
        $batch->forceFill([
            'state' => 'failed',
            'lease_token' => null,
            'claimed_at' => null,
            'available_at' => null,
            'last_dispatch_error_code' => 'queue_dispatch_exhausted',
            'completed_at' => now(),
        ])->save();
    }

    private function cancelForRevokedAuthority(BroadcastCampaign $campaign, Carbon $cancelledAt): void
    {
        $batches = BroadcastBatch::query()
            ->where('organization_id', $campaign->organization_id)
            ->where('campaign_id', $campaign->getKey())
            ->where('snapshot_id', $campaign->audience_snapshot_id)
            ->whereIn('state', ['pending', 'claimed'])
            ->lockForUpdate()
            ->get();
        foreach ($batches as $batch) {
            $this->markClaimedRecipientsUnknown($batch);
            BroadcastRecipient::query()
                ->where('organization_id', $campaign->organization_id)
                ->where('batch_id', $batch->getKey())
                ->where('state', BroadcastRecipientState::Pending->value)
                ->update([
                    'state' => BroadcastRecipientState::Failed->value,
                    'lease_token' => null,
                    'claimed_at' => null,
                    'next_attempt_at' => null,
                    'last_error_code' => 'authorization_revoked',
                    'render_context' => [],
                    'updated_at' => now(),
                ]);
            $batch->forceFill([
                'state' => 'failed',
                'lease_token' => null,
                'claimed_at' => null,
                'available_at' => null,
                'last_dispatch_error_code' => 'authorization_revoked',
                'completed_at' => now(),
            ])->save();
        }
        $this->reconcileCampaignCounts($campaign);
        $campaign->forceFill([
            'state' => BroadcastCampaignState::Cancelled,
            'cancelled_at' => $cancelledAt,
            'next_dispatch_at' => null,
            'last_dispatch_error_code' => 'authorization_revoked',
        ])->save();
        $this->audit->handle(
            Organization::query()->findOrFail($campaign->organization_id),
            null,
            'broadcast.campaign.execution_blocked',
            BroadcastCampaign::class,
            (string) $campaign->getKey(),
            ['reason' => 'creator_authority_revoked'],
        );
    }

    private function cancelForInvalidSnapshot(BroadcastCampaign $campaign, Carbon $cancelledAt, string $reason): void
    {
        $batches = BroadcastBatch::query()
            ->where('organization_id', $campaign->organization_id)
            ->where('campaign_id', $campaign->getKey())
            ->whereIn('state', ['pending', 'claimed'])
            ->lockForUpdate()
            ->get();
        foreach ($batches as $batch) {
            $this->markClaimedRecipientsUnknown($batch);
            BroadcastRecipient::query()
                ->where('organization_id', $campaign->organization_id)
                ->where('batch_id', $batch->getKey())
                ->where('state', BroadcastRecipientState::Pending->value)
                ->update([
                    'state' => BroadcastRecipientState::Failed->value,
                    'lease_token' => null,
                    'claimed_at' => null,
                    'next_attempt_at' => null,
                    'last_error_code' => $reason,
                    'render_context' => [],
                    'updated_at' => now(),
                ]);
            $batch->forceFill([
                'state' => 'failed',
                'lease_token' => null,
                'claimed_at' => null,
                'available_at' => null,
                'last_dispatch_error_code' => $reason,
                'completed_at' => now(),
            ])->save();
        }
        $this->reconcileCampaignCounts($campaign);
        $campaign->forceFill([
            'state' => BroadcastCampaignState::Cancelled,
            'cancelled_at' => $cancelledAt,
            'next_dispatch_at' => null,
            'last_dispatch_error_code' => $reason,
        ])->save();
        $this->audit->handle(
            Organization::query()->findOrFail($campaign->organization_id),
            null,
            'broadcast.campaign.execution_blocked',
            BroadcastCampaign::class,
            (string) $campaign->getKey(),
            ['reason' => $reason],
        );
    }

    private function markClaimedRecipientsUnknown(BroadcastBatch $batch): void
    {
        $recipients = BroadcastRecipient::query()
            ->where('organization_id', $batch->organization_id)
            ->where('batch_id', $batch->getKey())
            ->where('state', BroadcastRecipientState::Claimed->value)
            ->lockForUpdate()
            ->get();
        foreach ($recipients as $recipient) {
            $attempt = BroadcastDeliveryAttempt::query()
                ->where('organization_id', $batch->organization_id)
                ->where('recipient_id', $recipient->getKey())
                ->where('attempt_number', $recipient->attempt_count)
                ->lockForUpdate()
                ->first();
            if ($attempt === null) {
                $retry = $recipient->attempt_count < self::MAX_RECIPIENT_ATTEMPTS;
                $recipient->forceFill([
                    'state' => $retry ? BroadcastRecipientState::Pending : BroadcastRecipientState::Failed,
                    'lease_token' => null,
                    'claimed_at' => null,
                    'next_attempt_at' => $retry ? now()->addMinutes(5) : null,
                    'last_error_code' => 'delivery_pre_send_failure',
                    'render_context' => $retry ? $recipient->render_context : [],
                ])->save();

                continue;
            }
            if ($attempt->outcome === NotificationDeliveryOutcome::InFlight) {
                $attempt->forceFill([
                    'outcome' => NotificationDeliveryOutcome::Unknown,
                    'error_code' => 'delivery_outcome_unknown',
                    'completed_at' => now(),
                ])->save();
            }
            $recipient->forceFill(match ($attempt->outcome) {
                NotificationDeliveryOutcome::Delivered => [
                    'state' => BroadcastRecipientState::Delivered,
                    'lease_token' => null,
                    'claimed_at' => null,
                    'next_attempt_at' => null,
                    'delivered_at' => $attempt->completed_at ?? now(),
                    'last_error_code' => null,
                    'provider_reference' => $attempt->provider_reference,
                    'render_context' => [],
                ],
                NotificationDeliveryOutcome::Suppressed => [
                    'state' => BroadcastRecipientState::Suppressed,
                    'lease_token' => null,
                    'claimed_at' => null,
                    'next_attempt_at' => null,
                    'exclusion_code' => $attempt->error_code,
                    'render_context' => [],
                ],
                default => [
                    'state' => BroadcastRecipientState::Failed,
                    'lease_token' => null,
                    'claimed_at' => null,
                    'next_attempt_at' => null,
                    'last_error_code' => $attempt->error_code ?? 'delivery_outcome_unknown',
                    'render_context' => [],
                ],
            })->save();
        }
    }

    private function completeWhenNoWorkRemains(BroadcastCampaign $campaign): void
    {
        DB::transaction(function () use ($campaign): void {
            $locked = BroadcastCampaign::query()
                ->where('organization_id', $campaign->organization_id)
                ->whereKey($campaign->getKey())
                ->lockForUpdate()
                ->first();
            if ($locked === null || $locked->state !== BroadcastCampaignState::Dispatching) {
                return;
            }

            $unfinished = BroadcastBatch::query()
                ->where('organization_id', $locked->organization_id)
                ->where('campaign_id', $locked->getKey())
                ->where('snapshot_id', $locked->audience_snapshot_id)
                ->whereIn('state', ['pending', 'claimed'])
                ->exists();
            if ($unfinished) {
                $next = BroadcastBatch::query()
                    ->where('organization_id', $locked->organization_id)
                    ->where('campaign_id', $locked->getKey())
                    ->where('snapshot_id', $locked->audience_snapshot_id)
                    ->whereIn('state', ['pending', 'claimed'])
                    ->whereNotNull('available_at')
                    ->min('available_at');
                $locked->forceFill(['next_dispatch_at' => $next === null ? now()->addMinute() : $next])->save();

                return;
            }

            $this->reconcileCampaignCounts($locked);
            $locked->forceFill([
                'state' => BroadcastCampaignState::Completed,
                'completed_at' => now(),
                'next_dispatch_at' => null,
            ])->save();
        });
    }

    private function reconcileCampaignCounts(BroadcastCampaign $campaign): void
    {
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
}
