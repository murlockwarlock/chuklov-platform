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
}
