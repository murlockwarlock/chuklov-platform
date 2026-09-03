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
use Throwable;

final readonly class UpdateBroadcastCampaign
{
    public function __construct(
        private BroadcastAuthorization $authorization,
        private BroadcastCampaignInput $input,
        private RecordAuditEvent $audit,
        private BroadcastCampaignMedia $media,
    ) {}

    /** @param array<string, mixed> $attributes */
    public function handle(User $actor, BroadcastCampaign $campaign, array $attributes): BroadcastCampaign
    {
        $organization = $this->authorization->manage($actor);
        if ((int) $campaign->organization_id !== $organization->getKey()) {
            throw new AuthorizationException('The campaign is outside the current organization.');
        }
        $hasMediaReplacement = array_key_exists('media', $attributes)
            || filled($attributes['media_image'] ?? null)
            || filled($attributes['media_url'] ?? null);
        if (! (bool) ($attributes['remove_media'] ?? false)
            && ! $hasMediaReplacement
            && $campaign->media !== null) {
            $attributes['media'] = $campaign->media;
        }
        $normalized = $this->input->normalize($organization->getKey(), $attributes);
        $mediaInput = $normalized['media_input'] ?? null;
        unset($normalized['media_input']);
        $storedPath = null;
        $resolvedMedia = null;

        try {
            if ($mediaInput !== null) {
                $resolvedMedia = $this->media->resolve($organization->getKey(), $mediaInput, $storedPath);
            }

            return DB::transaction(function () use ($actor, $campaign, $normalized, $organization, $mediaInput, $resolvedMedia): BroadcastCampaign {
                $locked = BroadcastCampaign::query()->where('organization_id', $organization->getKey())->whereKey($campaign->getKey())->lockForUpdate()->firstOrFail();
                if ($locked->state !== BroadcastCampaignState::Draft) {
                    throw ValidationException::withMessages(['name' => 'После запуска рассылку нельзя изменить.']);
                }
                $previousSnapshotId = $locked->audience_snapshot_id;
                if ($previousSnapshotId !== null) {
                    $oldBatchIds = BroadcastBatch::query()
                        ->where('organization_id', $organization->getKey())
                        ->where('campaign_id', $locked->getKey())
                        ->where('snapshot_id', $previousSnapshotId)
                        ->pluck('id');
                    if ($oldBatchIds->isNotEmpty()) {
                        $oldRecipients = BroadcastRecipient::query()
                            ->where('organization_id', $organization->getKey())
                            ->whereIn('batch_id', $oldBatchIds)
                            ->whereIn('state', [BroadcastRecipientState::Pending->value, BroadcastRecipientState::Claimed->value])
                            ->lockForUpdate()
                            ->get();
                        foreach ($oldRecipients as $recipient) {
                            if ($recipient->state === BroadcastRecipientState::Claimed) {
                                $attempt = BroadcastDeliveryAttempt::query()
                                    ->where('organization_id', $organization->getKey())
                                    ->where('recipient_id', $recipient->getKey())
                                    ->where('attempt_number', $recipient->attempt_count)
                                    ->lockForUpdate()
                                    ->first();
                                if ($attempt?->outcome === NotificationDeliveryOutcome::InFlight) {
                                    $attempt->forceFill([
                                        'outcome' => NotificationDeliveryOutcome::Unknown,
                                        'error_code' => 'delivery_outcome_unknown',
                                        'completed_at' => now(),
                                    ])->save();
                                }
                            }
                            $recipient->forceFill([
                                'state' => BroadcastRecipientState::Failed->value,
                                'lease_token' => null,
                                'claimed_at' => null,
                                'next_attempt_at' => null,
                                'last_error_code' => 'snapshot_superseded',
                                'render_context' => [],
                            ])->save();
                        }
                        BroadcastBatch::query()
                            ->where('organization_id', $organization->getKey())
                            ->whereIn('id', $oldBatchIds)
                            ->whereIn('state', ['pending', 'claimed'])
                            ->update([
                                'state' => 'failed',
                                'lease_token' => null,
                                'claimed_at' => null,
                                'available_at' => null,
                                'last_dispatch_error_code' => 'snapshot_superseded',
                                'completed_at' => now(),
                                'updated_at' => now(),
                            ]);
                    }
                }
                $changes = $normalized;
                if ($mediaInput !== null) {
                    $changes['media'] = $resolvedMedia;
                }
                $locked->forceFill([
                    ...$changes,
                    'draft_version' => $locked->draft_version + 1,
                    'audience_snapshot_id' => null,
                    'audience_count' => 0,
                    'sent_count' => 0,
                    'delivered_count' => 0,
                    'failed_count' => 0,
                    'suppressed_count' => 0,
                ])->save();
                $this->audit->handle($organization, $actor, 'broadcast.campaign.updated', BroadcastCampaign::class, (string) $locked->getKey(), ['draft_version' => $locked->draft_version]);

                return $locked->refresh();
            });
        } catch (Throwable $exception) {
            if ($storedPath !== null) {
                $this->media->discard($organization->getKey(), $storedPath);
            }

            throw $exception;
        }
    }
}
