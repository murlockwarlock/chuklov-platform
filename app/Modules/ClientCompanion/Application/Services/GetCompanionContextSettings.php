<?php

namespace App\Modules\ClientCompanion\Application\Services;

use App\Modules\Organizations\Domain\Enums\OrganizationSettingKey;
use App\Modules\Organizations\Domain\Models\Organization;

final class GetCompanionContextSettings
{
    /** @return array{first_exchanges: int, recent_exchanges: int} */
    public function handle(Organization $organization): array
    {
        $settings = $organization->settings()
            ->whereIn('setting_key', [
                OrganizationSettingKey::CompanionContextFirstExchanges->value,
                OrganizationSettingKey::CompanionContextRecentExchanges->value,
            ])
            ->get()
            ->keyBy('setting_key');

        $firstSetting = $settings->get(OrganizationSettingKey::CompanionContextFirstExchanges->value);
        $recentSetting = $settings->get(OrganizationSettingKey::CompanionContextRecentExchanges->value);

        return [
            'first_exchanges' => $this->bounded(
                (int) ($firstSetting === null ? config('ai.companion.context_first_exchanges', 2) : $firstSetting->integer_value),
            ),
            'recent_exchanges' => $this->bounded(
                (int) ($recentSetting === null ? config('ai.companion.context_recent_exchanges', 10) : $recentSetting->integer_value),
            ),
        ];
    }

    private function bounded(int $value): int
    {
        return min(20, max(0, $value));
    }
}
