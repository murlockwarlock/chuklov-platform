<?php

namespace App\Modules\Sessions\Application;

use App\Modules\Organizations\Domain\Enums\OrganizationSettingKey;
use App\Modules\Organizations\Domain\Models\OrganizationSetting;

final class MedicalVitalsSettings
{
    /** @return array<string, array<string, mixed>> */
    public static function sourceExamples(): array
    {
        return [
            'sys' => self::definition('SYS', 'мм рт. ст.', redHigh: 180, redHighInclusive: true),
            'dia' => self::definition('DIA', 'мм рт. ст.', redHigh: 110, redHighInclusive: true),
            'pulse' => self::definition('Пульс', 'уд/мин', redLow: 40, redHigh: 100),
            'pi' => self::definition('Индекс перфузии', '%'),
            'spo2' => self::definition('Сатурация', '%', redLow: 90, attentionLow: 93, attentionHigh: 94),
        ];
    }

    /** @return array<string, array<string, mixed>> */
    public function forOrganization(int $organizationId): array
    {
        $setting = OrganizationSetting::query()
            ->where('organization_id', $organizationId)
            ->where('setting_key', OrganizationSettingKey::MedicalVitalsThresholds->value)
            ->first();
        if (! $setting instanceof OrganizationSetting || ! is_string($setting->string_value)) {
            return [];
        }

        $value = json_decode($setting->string_value, true);

        return is_array($value) ? $value : [];
    }

    /** @return array<string, mixed> */
    private static function definition(
        string $label,
        string $unit,
        int|float|null $redLow = null,
        int|float|null $redHigh = null,
        bool $redLowInclusive = false,
        bool $redHighInclusive = false,
        int|float|null $attentionLow = null,
        int|float|null $attentionHigh = null,
    ): array {
        return [
            'label' => $label,
            'unit' => $unit,
            'red_low' => $redLow,
            'red_high' => $redHigh,
            'red_low_inclusive' => $redLowInclusive,
            'red_high_inclusive' => $redHighInclusive,
            'attention_low' => $attentionLow,
            'attention_high' => $attentionHigh,
        ];
    }
}
