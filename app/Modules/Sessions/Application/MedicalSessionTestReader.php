<?php

namespace App\Modules\Sessions\Application;

use App\Modules\MedicalProfiles\Domain\Contracts\MedicalEncryptorInterface;
use App\Modules\Sessions\Application\DTOs\MedicalSessionRootCauseData;
use App\Modules\Sessions\Application\DTOs\MedicalSessionTestData;
use App\Modules\Sessions\Domain\Models\MedicalSession;
use App\Modules\Sessions\Domain\Models\MedicalSessionRootCause;
use App\Modules\Sessions\Domain\Models\MedicalSessionTestResult;
use App\Modules\Sessions\Domain\Models\MedicalTestDefinition;

final readonly class MedicalSessionTestReader
{
    public function __construct(private MedicalEncryptorInterface $encryptor) {}

    /** @return list<MedicalSessionTestData> */
    public function tests(MedicalSession $session): array
    {
        return array_values($session->testResults()
            ->with(['definition', 'beforeValue', 'afterValue', 'causes'])
            ->get()
            ->map(static fn (MedicalSessionTestResult $result): MedicalSessionTestData => new MedicalSessionTestData(
                testId: (int) $result->medical_test_definition_id,
                testName: self::testName($result),
                beforeValueId: $result->before_value_id === null ? null : (int) $result->before_value_id,
                beforeLabel: $result->beforeValue?->label,
                beforeAbnormal: (bool) ($result->beforeValue && $result->beforeValue->is_abnormal),
                afterValueId: $result->after_value_id === null ? null : (int) $result->after_value_id,
                afterLabel: $result->afterValue?->label,
                afterAbnormal: (bool) ($result->afterValue && $result->afterValue->is_abnormal),
                causes: array_values($result->causes->map(static fn ($cause): array => [
                    'id' => (int) $cause->getKey(),
                    'name' => (string) $cause->name,
                ])->values()->all()),
            ))->all());
    }

    /** @return list<MedicalSessionRootCauseData> */
    public function rootCauses(MedicalSession $session): array
    {
        return array_values($session->rootCauses()
            ->with('cause')
            ->get()
            ->map(fn (MedicalSessionRootCause $rootCause): MedicalSessionRootCauseData => new MedicalSessionRootCauseData(
                causeId: $rootCause->medical_cause_definition_id === null ? null : (int) $rootCause->medical_cause_definition_id,
                causeName: $rootCause->cause?->name,
                freeText: $rootCause->free_text === null
                    ? null
                    : $this->encryptor->decryptField((int) $session->organization_id, $rootCause->free_text, (int) $rootCause->encryption_key_version),
            ))->all());
    }

    /** @return list<array{id:int,name:string}> */
    public function causes(MedicalSession $session): array
    {
        if ($session->causes_manual_override) {
            return array_values($session->causes()
                ->orderBy('medical_cause_definitions.sort_order')
                ->orderBy('medical_cause_definitions.id')
                ->get(['medical_cause_definitions.id', 'medical_cause_definitions.name'])
                ->map(static fn ($cause): array => [
                    'id' => (int) $cause->getKey(),
                    'name' => (string) $cause->name,
                ])
                ->values()->all());
        }

        return array_values(collect($this->tests($session))
            ->flatMap(static fn (MedicalSessionTestData $test): array => $test->causes)
            ->unique('id')
            ->values()
            ->all());
    }

    private static function testName(MedicalSessionTestResult $result): string
    {
        $definition = $result->definition;

        return $definition instanceof MedicalTestDefinition ? (string) $definition->name : __('Удалённый тест');
    }
}
