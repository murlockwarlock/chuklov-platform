<?php

namespace App\Modules\MedicalProfiles\Application;

use App\Modules\MedicalProfiles\Domain\Models\MedicalFrequencyPeriodUnit;
use Illuminate\Validation\ValidationException;

final class MedicalFrequencyPeriodCatalog
{
    /** @var array<string, string> */
    private const DEFAULTS = [
        'hour' => 'час',
        'day' => 'день',
        'week' => 'неделя',
        'month' => 'месяц',
        'year' => 'год',
    ];

    public function ensureDefaults(int $organizationId): void
    {
        foreach (self::DEFAULTS as $key => $label) {
            MedicalFrequencyPeriodUnit::query()->firstOrCreate(
                ['organization_id' => $organizationId, 'key' => $key],
                ['label' => $label, 'is_active' => true, 'sort_order' => array_search($key, array_keys(self::DEFAULTS), true)],
            );
        }
    }

    /** @return array<string, string> */
    public function options(int $organizationId): array
    {
        $this->ensureDefaults($organizationId);

        return MedicalFrequencyPeriodUnit::query()
            ->where('organization_id', $organizationId)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->pluck('label', 'key')
            ->map(static fn (mixed $label): string => (string) $label)
            ->all();
    }

    public function assertActive(int $organizationId, string $key): void
    {
        $this->ensureDefaults($organizationId);
        if (! MedicalFrequencyPeriodUnit::query()->where('organization_id', $organizationId)->where('key', $key)->where('is_active', true)->exists()) {
            throw ValidationException::withMessages(['pains' => 'Выбранный период частоты недоступен.']);
        }
    }
}
