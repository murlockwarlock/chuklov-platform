<?php

namespace App\Modules\Sessions\Application;

use App\Modules\Sessions\Domain\Models\MedicalCauseDefinition;
use App\Modules\Sessions\Domain\Models\MedicalTestDefinition;
use App\Modules\Sessions\Domain\Models\MedicalTestValue;
use Illuminate\Database\Eloquent\Collection;

final class MedicalClinicalCatalog
{
    /** @return array<int, string> */
    public function testOptions(int $organizationId): array
    {
        return $this->testProtocolRows($organizationId)
            ->mapWithKeys(static fn (MedicalTestDefinition $test): array => [
                $test->getKey() => filled($test->section) ? $test->section.': '.$test->name : $test->name,
            ])
            ->all();
    }

    /** @param array<int, int> $selectedTestIds
     * @return Collection<int, MedicalTestDefinition>
     */
    public function testProtocolRows(int $organizationId, array $selectedTestIds = []): Collection
    {
        return MedicalTestDefinition::query()
            ->where('organization_id', $organizationId)
            ->when($selectedTestIds === [], fn ($query) => $query->where('is_active', true))
            ->when($selectedTestIds !== [], fn ($query) => $query->where(fn ($query) => $query
                ->where('is_active', true)
                ->orWhereIn('id', $selectedTestIds)))
            ->orderBy('section')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get(['id', 'name', 'section', 'is_active']);
    }

    /** @param array<int, mixed> $results
     * @return array{before:int,after:int}
     */
    public function abnormalCounts(int $organizationId, array $results): array
    {
        $valueIds = collect($results)
            ->filter(static fn (mixed $result): bool => is_array($result))
            ->flatMap(static fn (array $result): array => [$result['before_value_id'] ?? null, $result['after_value_id'] ?? null])
            ->filter(static fn (mixed $id): bool => $id !== null && (int) $id > 0)
            ->map(static fn (mixed $id): int => (int) $id)
            ->unique()
            ->values();
        if ($valueIds->isEmpty()) {
            return ['before' => 0, 'after' => 0];
        }
        $abnormalIds = MedicalTestValue::query()
            ->where('organization_id', $organizationId)
            ->whereIn('id', $valueIds->all())
            ->where('is_abnormal', true)
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();
        $counts = ['before' => 0, 'after' => 0];
        foreach ($results as $result) {
            if (! is_array($result)) {
                continue;
            }
            if (in_array((int) ($result['before_value_id'] ?? 0), $abnormalIds, true)) {
                $counts['before']++;
            }
            if (in_array((int) ($result['after_value_id'] ?? 0), $abnormalIds, true)) {
                $counts['after']++;
            }
        }

        return $counts;
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
