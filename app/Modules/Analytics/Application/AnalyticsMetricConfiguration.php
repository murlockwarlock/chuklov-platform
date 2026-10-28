<?php

namespace App\Modules\Analytics\Application;

use App\Models\User;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Application\SetOrganizationSetting;
use App\Modules\Organizations\Domain\Enums\OrganizationSettingKey;
use Illuminate\Validation\ValidationException;

final readonly class AnalyticsMetricConfiguration
{
    public function __construct(
        private OrganizationContext $context,
        private SetOrganizationSetting $setter,
    ) {}

    /** @return array<string, array{label: string, formula_reference: string|null, configured: bool}> */
    public function values(): array
    {
        $settings = $this->context->organization()->settings()
            ->whereIn('setting_key', [
                OrganizationSettingKey::AnalyticsTherapyEffectivenessFormulaReference->value,
                OrganizationSettingKey::AnalyticsLtvPercentFormulaReference->value,
            ])
            ->get()
            ->keyBy('setting_key');

        return [
            'therapy_effectiveness' => $this->metricValue(
                label: 'Эффективность терапии, %',
                setting: $settings->get(OrganizationSettingKey::AnalyticsTherapyEffectivenessFormulaReference->value)?->string_value,
            ),
            'ltv_percent' => $this->metricValue(
                label: 'LTV клиентов, %',
                setting: $settings->get(OrganizationSettingKey::AnalyticsLtvPercentFormulaReference->value)?->string_value,
            ),
        ];
    }

    /** @param array<string, mixed> $data */
    public function save(User $actor, array $data): void
    {
        $references = [
            [
                'key' => OrganizationSettingKey::AnalyticsTherapyEffectivenessFormulaReference,
                'value' => $data['therapy_effectiveness_formula_reference'] ?? null,
            ],
            [
                'key' => OrganizationSettingKey::AnalyticsLtvPercentFormulaReference,
                'value' => $data['ltv_percent_formula_reference'] ?? null,
            ],
        ];

        foreach ($references as $referenceData) {
            $key = $referenceData['key'];
            $reference = $referenceData['value'];
            if ($reference !== null && ! is_string($reference)) {
                throw ValidationException::withMessages([$key->value => 'Описание формулы заполнено некорректно.']);
            }

            $normalized = trim((string) ($reference ?? ''));
            if (mb_strlen($normalized) > 2000) {
                throw ValidationException::withMessages([$key->value => 'Описание формулы не должно превышать 2000 символов.']);
            }

            $this->setter->handle($actor, $key, $normalized);
        }
    }

    /** @return array{label: string, formula_reference: string|null, configured: bool} */
    private function metricValue(string $label, ?string $setting): array
    {
        $reference = trim((string) $setting);

        return [
            'label' => $label,
            'formula_reference' => $reference === '' ? null : $reference,
            'configured' => $reference !== '',
        ];
    }
}
