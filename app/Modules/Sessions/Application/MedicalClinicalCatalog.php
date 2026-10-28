<?php

namespace App\Modules\Sessions\Application;

use App\Modules\Sessions\Domain\Models\MedicalCauseDefinition;
use App\Modules\Sessions\Domain\Models\MedicalTestDefinition;
use App\Modules\Sessions\Domain\Models\MedicalTestValue;

final class MedicalClinicalCatalog
{
    /** @return array<int, string> */
    public function testOptions(int $organizationId): array
    {
        return MedicalTestDefinition::query()
            ->where('organization_id', $organizationId)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get(['id', 'name', 'section'])
            ->mapWithKeys(static fn (MedicalTestDefinition $test): array => [
                $test->getKey() => filled($test->section) ? $test->section.': '.$test->name : $test->name,
            ])
            ->all();
    }

    /** @return array<int, string> */
    public function testOptionsForSelection(int $organizationId, ?int $selectedTestId): array
    {
        $options = $this->testOptions($organizationId);
        if ($selectedTestId === null || $selectedTestId < 1 || array_key_exists($selectedTestId, $options)) {
            return $options;
        }

        $test = MedicalTestDefinition::query()
            ->where('organization_id', $organizationId)
            ->whereKey($selectedTestId)
            ->where('is_active', false)
            ->first(['id', 'name', 'section']);
        if ($test !== null) {
            $options[(int) $test->getKey()] = filled($test->section)
                ? $test->section.': '.$test->name.' ('.__('неактивен').')'
                : $test->name.' ('.__('неактивен').')';
        }

        return $options;
    }

    /** @return array<int, string> */
    public function valueOptions(int $organizationId, int $testId): array
    {
        return MedicalTestValue::query()
            ->where('organization_id', $organizationId)
            ->where('medical_test_definition_id', $testId)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('label')
            ->get(['id', 'label', 'is_abnormal'])
            ->mapWithKeys(static fn (MedicalTestValue $value): array => [
                $value->getKey() => $value->label.($value->is_abnormal ? ' · нарушение' : ''),
            ])
            ->all();
    }

    /** @param array<int, mixed> $selectedValueIds
     * @return array<int, string>
     */
    public function valueOptionsForSelection(int $organizationId, int $testId, array $selectedValueIds): array
    {
        $options = $this->valueOptions($organizationId, $testId);
        $ids = array_values(array_unique(array_map(static fn (mixed $id): int => (int) $id, $selectedValueIds)));
        if ($testId < 1 || $ids === []) {
            return $options;
        }

        MedicalTestValue::query()
            ->where('organization_id', $organizationId)
            ->where('medical_test_definition_id', $testId)
            ->whereIn('id', $ids)
            ->where('is_active', false)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get(['id', 'label', 'is_abnormal'])
            ->each(function (MedicalTestValue $value) use (&$options): void {
                $options[(int) $value->getKey()] = $value->label.($value->is_abnormal ? ' · '.__('нарушение') : '').' ('.__('неактивно').')';
            });

        return $options;
    }

    /** @return array<int, string> */
    public function causeOptions(int $organizationId): array
    {
        return MedicalCauseDefinition::query()
            ->where('organization_id', $organizationId)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get(['id', 'name', 'group_name'])
            ->mapWithKeys(static fn (MedicalCauseDefinition $cause): array => [
                $cause->getKey() => filled($cause->group_name) ? $cause->group_name.': '.$cause->name : $cause->name,
            ])
            ->all();
    }

    /** @param array<int, mixed> $selectedCauseIds
     * @return array<int, string>
     */
    public function causeOptionsForSelection(int $organizationId, array $selectedCauseIds): array
    {
        $options = $this->causeOptions($organizationId);
        $ids = array_values(array_unique(array_map(static fn (mixed $id): int => (int) $id, $selectedCauseIds)));
        if ($ids === []) {
            return $options;
        }

        MedicalCauseDefinition::query()
            ->where('organization_id', $organizationId)
            ->whereIn('id', $ids)
            ->where('is_active', false)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get(['id', 'name', 'group_name'])
            ->each(function (MedicalCauseDefinition $cause) use (&$options): void {
                $label = filled($cause->group_name) ? $cause->group_name.': '.$cause->name : $cause->name;
                $options[(int) $cause->getKey()] = $label.' ('.__('неактивна').')';
            });

        return $options;
    }
}
