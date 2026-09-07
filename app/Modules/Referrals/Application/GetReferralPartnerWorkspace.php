<?php

namespace App\Modules\Referrals\Application;

use App\Models\User;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use App\Modules\Referrals\Domain\Enums\ReferralPartnerStatus;
use App\Modules\Referrals\Domain\Models\ReferralPartnerProfile;
use Carbon\CarbonImmutable;

final class GetReferralPartnerWorkspace
{
    public function __construct(
        private readonly OrganizationContext $context,
        private readonly OrganizationAuthorizer $authorizer,
        private readonly GetReferralPartnerOverview $overview,
    ) {}

    /** @return array<string, mixed> */
    public function handle(User $actor, ReferralPartnerProfile|int $profile): array
    {
        $organization = $this->context->organization();
        $this->authorizer->authorize($actor, $organization, OrganizationPermission::ViewClients);
        $profileId = $profile instanceof ReferralPartnerProfile ? $profile->getKey() : $profile;
        $profileRecord = ReferralPartnerProfile::query()
            ->where('organization_id', $organization->getKey())
            ->whereKey($profileId)
            ->with('client')
            ->firstOrFail();

        $client = $profileRecord->client;
        abort_unless($client instanceof Client, 404);
        $status = ReferralPartnerStatus::tryFrom((string) $profileRecord->getRawOriginal('status'));

        return [
            'partner' => [
                'name' => $client->full_name ?: 'Клиент без имени',
                'clientId' => $profileRecord->client_id,
                'status' => $status?->label() ?? 'Неизвестно',
                'activatedAt' => $profileRecord->getRawOriginal('activated_at') === null
                    ? null
                    : CarbonImmutable::parse((string) $profileRecord->getRawOriginal('activated_at'))->toIso8601String(),
            ],
            'overview' => $this->overview->handle($client),
        ];
    }
}
