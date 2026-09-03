<?php

namespace App\Modules\Broadcasts\Application;

use App\Models\User;
use App\Modules\Broadcasts\Domain\Enums\BroadcastCampaignState;
use App\Modules\Broadcasts\Domain\Models\BroadcastCampaign;
use App\Modules\Security\Application\RecordAuditEvent;
use Illuminate\Support\Facades\DB;
use Throwable;

final readonly class CreateBroadcastCampaign
{
    public function __construct(
        private BroadcastAuthorization $authorization,
        private BroadcastCampaignInput $input,
        private CreateBroadcastMessageTemplate $messages,
        private RecordAuditEvent $audit,
        private BroadcastCampaignMedia $media,
    ) {}

    /** @param array<string, mixed> $attributes */
    public function handle(User $actor, array $attributes): BroadcastCampaign
    {
        $organization = $this->authorization->manage($actor);
        $normalized = $this->input->normalize($organization->getKey(), $attributes);
        $mediaPath = null;

        try {
            $normalized['media'] = $this->media->resolve($organization->getKey(), $normalized['media_input'] ?? null, $mediaPath);
            unset($normalized['media_input']);

            return DB::transaction(function () use ($actor, $organization, $normalized): BroadcastCampaign {
                if ($normalized['message_mode'] === 'compose' && $normalized['message_body'] !== null) {
                    $version = $this->messages->handle(
                        actor: $actor,
                        organization: $organization,
                        campaignName: $normalized['name'],
                        body: $normalized['message_body'],
                    );
                    $normalized['template_version_ru_id'] = $version->getKey();
                }
                $campaign = new BroadcastCampaign;
                $campaign->forceFill([...$normalized, 'organization_id' => $organization->getKey(), 'created_by_user_id' => $actor->getKey(), 'state' => BroadcastCampaignState::Draft]);
                $campaign->save();
                $this->audit->handle($organization, $actor, 'broadcast.campaign.created', BroadcastCampaign::class, (string) $campaign->getKey(), ['send_mode' => $campaign->send_mode, 'channel_count' => count($campaign->channel_priority)]);

                return $campaign->refresh();
            });
        } catch (Throwable $exception) {
            if ($mediaPath !== null) {
                $this->media->discard($organization->getKey(), $mediaPath);
            }

            throw $exception;
        }
    }
}
