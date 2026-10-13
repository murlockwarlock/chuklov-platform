<?php

namespace App\Modules\ClientPortal\Application;

use App\Modules\Finance\Domain\Models\OrganizationCurrencyConfiguration;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Referrals\Application\ReferralRewardBalanceProjection;

final readonly class GetClientAvailableBonus
{
    public function __construct(private ReferralRewardBalanceProjection $balances) {}

    public function handle(Client $client): ?string
    {
        if (! OrganizationCurrencyConfiguration::query()->where('organization_id', $client->organization_id)->exists()) {
            return null;
        }
        $balance = $this->balances->serviceCredit($client);
        $available = $balance->available();

        return $available->isPositive() ? $available->toDecimalString().' '.$balance->currency->value : null;
    }
}
