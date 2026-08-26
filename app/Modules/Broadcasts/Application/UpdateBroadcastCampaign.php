<?php

namespace App\Modules\Broadcasts\Application;

use App\Models\User;
use App\Modules\Broadcasts\Domain\Enums\BroadcastCampaignState;
use App\Modules\Broadcasts\Domain\Models\BroadcastCampaign;
use App\Modules\Security\Application\RecordAuditEvent;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final readonly class UpdateBroadcastCampaign
{
    public function __construct(private BroadcastAuthorization $authorization, private BroadcastCampaignInput $input, private RecordAuditEvent $audit) {}

    /** @param array<string, mixed> $attributes */
    public function handle(User $actor, BroadcastCampaign $campaign, array $attributes): BroadcastCampaign
    {
        $organization = $this->authorization->manage($actor);
        if ((int) $campaign->organization_id !== $organization->getKey()) {
            throw new AuthorizationException('The campaign is outside the current organization.');
        }
        $normalized = $this->input->normalize($organization->getKey(), $attributes);

        return DB::transaction(function () use ($actor, $campaign, $normalized, $organization): BroadcastCampaign {
            $locked = BroadcastCampaign::query()->where('organization_id', $organization->getKey())->whereKey($campaign->getKey())->lockForUpdate()->firstOrFail();
            if ($locked->state !== BroadcastCampaignState::Draft) {
                throw ValidationException::withMessages(['name' => 'После запуска рассылку нельзя изменить.']);
            }
            $locked->forceFill([...$normalized, 'draft_version' => $locked->draft_version + 1])->save();
            $this->audit->handle($organization, $actor, 'broadcast.campaign.updated', BroadcastCampaign::class, (string) $locked->getKey(), ['draft_version' => $locked->draft_version]);

            return $locked->refresh();
        });
    }
}
