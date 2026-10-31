<?php

namespace App\Modules\Analytics\Application;

use App\Modules\Organizations\Domain\Enums\OrganizationSettingKey;
use App\Modules\Organizations\Domain\Models\OrganizationSetting;

final class ClientSessionAxisCatalog
{
    public const string Chronological = 'client_chronological';

    public const string SelectedPeriod = 'selected_period_chronological';

    /** @return array<string, array{label: string, version: string}> */
    public static function definitions(): array
    {
        return [
            self::Chronological => [
                'label' => 'Порядок сеансов клиента от первого к последнему',
                'version' => 'client-chronological-v1',
            ],
            self::SelectedPeriod => [
                'label' => 'Порядок сеансов внутри выбранного периода',
                'version' => 'selected-period-chronological-v1',
            ],
        ];
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        $options = [];
        foreach (self::definitions() as $key => $definition) {
            $options[$key] = $definition['label'];
        }

        return $options;
    }

    /** @return array{key: string, label: string, version: string} */
    public function selected(int $organizationId): array
    {
        $key = OrganizationSetting::query()
            ->where('organization_id', $organizationId)
            ->where('setting_key', OrganizationSettingKey::AnalyticsSessionAxisSemantics->value)
            ->value('string_value');
        $key = is_string($key) && array_key_exists($key, self::definitions()) ? $key : self::Chronological;
        $definition = self::definitions()[$key];

        return ['key' => $key, ...$definition];
    }

    public function ranksWithinPeriod(int $organizationId): bool
    {
        return $this->selected($organizationId)['key'] === self::SelectedPeriod;
    }
}
