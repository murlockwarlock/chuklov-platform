<?php

namespace App\Modules\Referrals\Application;

use App\Models\User;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use App\Modules\Referrals\Domain\Enums\ReferralPartnerStatus;
use App\Modules\Referrals\Domain\Models\ReferralCampaignLink;
use App\Modules\Referrals\Domain\Models\ReferralPartnerProfile;
use App\Modules\Security\Application\RecordAuditEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class DeactivateReferralPartner
{
    public function __construct(
        private readonly OrganizationContext $context,
        private readonly OrganizationAuthorizer $authorizer,
        private readonly RecordAuditEvent $audit,
    ) {}

    public function handle(Client $client, User $actor): ReferralPartnerProfile
    {
        $organization = $this->context->organization();
        $this->authorizer->authorize($actor, $organization, OrganizationPermission::ManageClients);
        abort_unless((int) $client->organization_id === (int) $organization->getKey(), 404);

        return DB::transaction(function () use ($actor, $organization, $client): ReferralPartnerProfile {
            $profile = ReferralPartnerProfile::query()
                ->where('organization_id', $organization->getKey())
                ->where('client_id', $client->getKey())
                ->lockForUpdate()
                ->first();

            if (! $profile instanceof ReferralPartnerProfile) {
                throw ValidationException::withMessages(['partner' => 'Клиент не подключён к партнёрской программе.']);
            }

            if (! $profile->isActive()) {
                return $profile;
            }

            $now = now();
            $activeLinks = ReferralCampaignLink::query()
                ->where('organization_id', $organization->getKey())
                ->where('partner_profile_id', $profile->getKey())
                ->where('is_active', true)
                ->lockForUpdate()
                ->get();

            $profile->forceFill([
                'status' => ReferralPartnerStatus::Inactive,
                'deactivated_at' => $now,
                'deactivated_by_user_id' => $actor->getKey(),
                'updated_at' => $now,
            ])->save();

            foreach ($activeLinks as $link) {
                $link->forceFill([
                    'is_active' => false,
                    'disabled_at' => $now,
                    'disabled_by_user_id' => $actor->getKey(),
                    'updated_at' => $now,
                ])->save();
            }

            $this->audit->handle(
                organization: $organization,
                actor: $actor,
                action: 'referral.partner.deactivated',
                targetType: ReferralPartnerProfile::class,
                targetId: (string) $profile->getKey(),
                metadata: [
                    'client_id' => $client->getKey(),
                    'campaign_link_count' => $activeLinks->count(),
                ],
            );

            return $profile->refresh();
        });
    }
}
