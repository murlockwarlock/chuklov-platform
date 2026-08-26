<?php

namespace App\Modules\Broadcasts\Application;

use App\Models\User;
use App\Modules\Broadcasts\Domain\Enums\BroadcastCampaignState;
use App\Modules\Broadcasts\Domain\Models\BroadcastCampaign;
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
            $locked->forceFill(['state' => BroadcastCampaignState::Cancelled, 'cancelled_at' => now()])->save();
            $this->audit->handle($organization, $actor, 'broadcast.campaign.cancelled', BroadcastCampaign::class, (string) $locked->getKey());
        });

        return $campaign->refresh();
    }
}
