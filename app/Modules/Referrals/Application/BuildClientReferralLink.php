<?php

namespace App\Modules\Referrals\Application;

use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Domain\Models\Organization;

final readonly class BuildClientReferralLink
{
    public function __construct(
        private EnsureReferralIdentity $identities,
        private BuildReferralTelegramUrl $telegramUrl,
    ) {}

    public function handle(Client $client): string
    {
        $organization = Organization::query()->findOrFail($client->organization_id);
        $identity = $this->identities->handleForOrganization($organization, $client);

        return $this->telegramUrl->handle($identity->public_code);
    }
}
