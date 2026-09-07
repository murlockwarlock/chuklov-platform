<?php

namespace App\Modules\Referrals\Application;

use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Referrals\Domain\Models\ClientReferralIdentity;
use App\Modules\Referrals\Domain\Models\ReferralCampaignLink;

final class ResolveReferralCode
{
    public function __construct(private readonly OrganizationContext $context) {}

    public function handle(int $organizationId, string $token): ReferralCampaignLink|ClientReferralIdentity|null
    {
        abort_unless($this->context->id() === $organizationId, 404);
        $token = trim($token);

        if ($token === '') {
            return null;
        }

        $campaignLink = ReferralCampaignLink::query()
            ->where('organization_id', $organizationId)
            ->where('public_token', $token)
            ->with('referralIdentity')
            ->first();

        if ($campaignLink instanceof ReferralCampaignLink) {
            return $campaignLink;
        }

        return ClientReferralIdentity::query()
            ->where('organization_id', $organizationId)
            ->where('public_code', $token)
            ->first();
    }
}
