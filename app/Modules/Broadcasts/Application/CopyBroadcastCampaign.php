<?php

namespace App\Modules\Broadcasts\Application;

use App\Models\User;
use App\Modules\Broadcasts\Domain\Enums\BroadcastCampaignState;
use App\Modules\Broadcasts\Domain\Models\BroadcastCampaign;
use App\Modules\Security\Application\RecordAuditEvent;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final readonly class CopyBroadcastCampaign
{
    public function __construct(
        private BroadcastAuthorization $authorization,
        private RecordAuditEvent $audit,
        private BroadcastCampaignName $name,
    ) {}

    public function handle(User $actor, BroadcastCampaign $campaign): BroadcastCampaign
    {
        $organization = $this->authorization->manage($actor);
        if ((int) $campaign->organization_id !== $organization->getKey()) {
            throw new AuthorizationException('The campaign is outside the current organization.');
        }
        if ($campaign->state === BroadcastCampaignState::Draft) {
            throw ValidationException::withMessages(['campaign' => 'Для повтора нужна уже запущенная рассылка.']);
        }

        return DB::transaction(function () use ($actor, $campaign, $organization): BroadcastCampaign {
            $source = BroadcastCampaign::query()
                ->where('organization_id', $organization->getKey())
                ->whereKey($campaign->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            if ($source->state === BroadcastCampaignState::Draft) {
                throw ValidationException::withMessages(['campaign' => 'Для повтора нужна уже запущенная рассылка.']);
            }

            $copy = new BroadcastCampaign;
            $copy->forceFill([
                'organization_id' => $organization->getKey(),
                'created_by_user_id' => $actor->getKey(),
                'name' => $this->name->copyName($source, $organization->getKey()),
                'state' => BroadcastCampaignState::Draft,
                'send_mode' => 'immediate',
                'audience_type' => $source->audience_type,
                'channel_priority' => $source->channel_priority,
                'segment_definition' => $source->segment_definition,
                'selected_client_ids' => $source->selected_client_ids,
                'message_mode' => $source->message_mode,
                'message_body' => $source->message_body,
                'delivery_mode' => $source->delivery_mode,
                'caption_position' => $source->caption_position,
                'media' => $source->media,
                'segment_summary' => $source->segment_summary,
                'template_version_ru_id' => $source->message_mode === 'saved_template' ? $source->template_version_ru_id : null,
                'template_version_en_id' => $source->message_mode === 'saved_template' ? $source->template_version_en_id : null,
                'draft_version' => 1,
                'audience_count' => 0,
                'sent_count' => 0,
                'delivered_count' => 0,
                'failed_count' => 0,
                'suppressed_count' => 0,
                'audience_snapshot_id' => null,
                'scheduled_at' => null,
                'dispatch_started_at' => null,
                'dispatch_attempt_count' => 0,
                'next_dispatch_at' => null,
                'last_dispatch_error_code' => null,
                'completed_at' => null,
                'cancelled_at' => null,
            ]);
            $copy->save();

            $this->audit->handle(
                organization: $organization,
                actor: $actor,
                action: 'broadcast.campaign.copied',
                targetType: BroadcastCampaign::class,
                targetId: (string) $copy->getKey(),
                metadata: ['source_campaign_id' => $source->getKey()],
            );

            return $copy->refresh();
        });
    }
}
