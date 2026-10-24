<?php

namespace App\Modules\Referrals\Application;

use App\Modules\Finance\Application\DisplayClientMoney;
use App\Modules\Finance\Domain\ValueObjects\Money;
use App\Modules\Identity\Domain\Models\Client;

final class GetClientReferralOverview
{
    public function __construct(private readonly GetReferralPartnerOverview $overview, private readonly DisplayClientMoney $displayMoney) {}

    /** @return array<string, mixed> */
    public function handle(Client $client, ?string $locale = null): array
    {
        $result = $this->overview->handle($client, $locale);
        if ($result['isPartner']) {
            return $result;
        }
        $amounts = [];
        foreach ($result['rewards']['balances'] as $balance) {
            foreach (['earnedMinor', 'accruedMinor', 'availableMinor', 'redeemedMinor', 'restoredMinor'] as $field) {
                $amounts[] = Money::ofMinor($balance[$field], $balance['currency']);
            }
        }
        foreach ($result['rewards']['history'] as $entry) {
            $amounts[] = Money::ofMinor($entry['amountMinor'], $entry['currency']);
        }
        foreach ($result['stats']['rewardEarned'] as $amount) {
            $amounts[] = Money::ofMinor($amount['amountMinor'], $amount['currency']);
        }
        $display = $this->displayMoney->handleMany($client, $amounts);
        $index = 0;
        foreach ($result['rewards']['balances'] as &$balance) {
            $balance['display'] = [];
            foreach (['earnedMinor', 'accruedMinor', 'availableMinor', 'redeemedMinor', 'restoredMinor'] as $field) {
                $balance['display'][$field] = $display[$index++];
            }
        }
        unset($balance);
        foreach ($result['rewards']['history'] as &$entry) {
            $entry['display'] = $display[$index++];
        }
        unset($entry);
        foreach ($result['stats']['rewardEarned'] as &$amount) {
            $amount['display'] = $display[$index++];
        }

        return $result;
    }
}
