<?php

namespace App\Modules\Referrals\Application;

use App\Modules\Identity\Domain\Models\Client;

final class GetClientReferralOverview
{
    public function __construct(private readonly GetReferralPartnerOverview $overview) {}

    /** @return array<string, mixed> */
    public function handle(Client $client): array
    {
        return $this->overview->handle($client);
    }
}
