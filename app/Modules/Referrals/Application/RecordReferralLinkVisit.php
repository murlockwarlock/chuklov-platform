<?php

namespace App\Modules\Referrals\Application;

use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Referrals\Domain\Models\ReferralCampaignLink;
use App\Modules\Referrals\Domain\Models\ReferralLinkVisit;
use Illuminate\Support\Facades\DB;

final class RecordReferralLinkVisit
{
    public function __construct(
        private readonly OrganizationContext $context,
        private readonly ResolveReferralCode $resolver,
    ) {}

    public function handle(string $token, string $sessionId): ?ReferralCampaignLink
    {
        $sessionId = trim($sessionId);

        if ($sessionId === '') {
            return null;
        }

        $organizationId = $this->context->id();

        return DB::transaction(function () use ($organizationId, $token, $sessionId): ?ReferralCampaignLink {
            $resolved = $this->resolver->handle($organizationId, $token);

            if (! $resolved instanceof ReferralCampaignLink) {
                return null;
            }

            $campaignLink = ReferralCampaignLink::query()
                ->where('organization_id', $organizationId)
                ->whereKey($resolved->getKey())
                ->lockForUpdate()
                ->first();

            if (! $campaignLink instanceof ReferralCampaignLink || ! $campaignLink->is_active) {
                return null;
            }

            $profile = $campaignLink->partnerProfile()->first();

            if ($profile === null || ! $profile->isActive()) {
                return null;
            }

            $visit = new ReferralLinkVisit;
            $visit->forceFill([
                'organization_id' => $organizationId,
                'campaign_link_id' => $campaignLink->getKey(),
                'session_hash' => hash('sha256', $sessionId),
                'occurred_at' => now(),
            ]);
            $visit->save();

            return $campaignLink->refresh();
        });
    }
}
