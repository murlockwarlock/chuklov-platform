<?php

namespace App\Modules\Referrals\Application;

use App\Modules\Channels\Application\ResolveTelegramMiniAppEntry;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Referrals\Domain\Models\ClientReferralIdentity;
use App\Modules\Referrals\Domain\Models\ReferralCampaignLink;
use App\Modules\Referrals\Domain\Models\ReferralPartnerProfile;

final class HandleReferralTelegramStart
{
    public function __construct(
        private readonly OrganizationContext $context,
        private readonly ResolveReferralCode $resolver,
        private readonly RecordReferralLinkVisit $visits,
        private readonly ResolveTelegramMiniAppEntry $entries,
    ) {}

    public function handle(string $payload, string $telegramExternalId): ?string
    {
        if (! str_starts_with($payload, 'ref_')) {
            return null;
        }

        $token = substr($payload, 4);

        if (preg_match('/^[A-Za-z0-9_-]{16,128}$/', $token) !== 1 || trim($telegramExternalId) === '') {
            return null;
        }

        $organizationId = $this->context->id();
        $resolved = $this->resolver->handle($organizationId, $token);

        if ($resolved instanceof ReferralCampaignLink) {
            $profile = $resolved->partnerProfile()->first();

            if (! $resolved->isActive() || ! $profile instanceof ReferralPartnerProfile || ! $profile->isActive()) {
                return null;
            }

            $this->visits->handle($token, 'telegram:'.$telegramExternalId.':'.$token);
        } elseif ($resolved instanceof ClientReferralIdentity) {
            $profile = ReferralPartnerProfile::query()
                ->where('organization_id', $organizationId)
                ->where('client_id', $resolved->client_id)
                ->first();

            if ($profile instanceof ReferralPartnerProfile && ! $profile->isActive()) {
                return null;
            }
        } else {
            return null;
        }

        return $this->entries->launchUrl('portal', ['referral_code' => $token]);
    }
}
