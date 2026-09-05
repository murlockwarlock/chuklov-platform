<?php

namespace App\Modules\Referrals\Application;

use App\Models\User;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use App\Modules\Referrals\Domain\Enums\ReferralCampaignChannel;
use App\Modules\Referrals\Domain\Models\ReferralCampaignLink;
use App\Modules\Security\Application\RecordAuditEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class DeactivateReferralCampaignLink
{
    public function __construct(
        private readonly OrganizationContext $context,
        private readonly OrganizationAuthorizer $authorizer,
        private readonly RecordAuditEvent $audit,
    ) {}

    public function handle(ReferralCampaignLink|int $link, Client|User $actor): ReferralCampaignLink
    {
        $organization = $this->context->organization();
        $actorClient = $actor instanceof Client ? $actor : null;

        if ($actor instanceof User) {
            $this->authorizer->authorize($actor, $organization, OrganizationPermission::ManageClients);
        } else {
            abort_unless((int) $actor->organization_id === (int) $organization->getKey(), 404);
        }

        return DB::transaction(function () use ($organization, $link, $actor, $actorClient): ReferralCampaignLink {
            $query = ReferralCampaignLink::query()
                ->where('organization_id', $organization->getKey())
                ->lockForUpdate();

            if ($link instanceof ReferralCampaignLink) {
                $query->whereKey($link->getKey());
            } else {
                $query->whereKey($link);
            }

            if ($actorClient instanceof Client) {
                $query->where('partner_client_id', $actorClient->getKey());
            }

            $campaignLink = $query->first();

            if (! $campaignLink instanceof ReferralCampaignLink) {
                throw ValidationException::withMessages(['link' => 'Реферальная ссылка не найдена.']);
            }

            if (! $campaignLink->is_active) {
                return $campaignLink;
            }

            $campaignLink->forceFill([
                'is_active' => false,
                'disabled_at' => now(),
                'disabled_by_user_id' => $actor instanceof User ? $actor->getKey() : null,
                'updated_at' => now(),
            ])->save();
            $this->audit->handle(
                organization: $organization,
                actor: $actor instanceof User ? $actor : null,
                action: 'referral.campaign_link.disabled',
                targetType: ReferralCampaignLink::class,
                targetId: (string) $campaignLink->getKey(),
                metadata: [
                    'client_id' => $campaignLink->partner_client_id,
                    'channel' => ReferralCampaignChannel::tryFrom((string) $campaignLink->getRawOriginal('channel'))?->value,
                ],
            );

            return $campaignLink->refresh();
        });
    }
}
