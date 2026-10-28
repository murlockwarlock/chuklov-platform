<?php

namespace App\Modules\Sessions\Application;

use App\Modules\MedicalProfiles\Domain\Contracts\MedicalEncryptorInterface;
use App\Modules\Sessions\Application\DTOs\MedicalSessionRootCauseData;
use App\Modules\Sessions\Application\DTOs\MedicalSessionTestData;
use App\Modules\Sessions\Domain\Models\MedicalSession;
use App\Modules\Sessions\Domain\Models\MedicalSessionRootCause;
use App\Modules\Sessions\Domain\Models\MedicalSessionTestResult;

final readonly class MedicalSessionTestReader
{
    public function __construct(private MedicalEncryptorInterface $encryptor) {}

    /** @return list<MedicalSessionTestData> */
    public function tests(MedicalSession $session): array
    {
        return $session->testResults()
            ->with(['definition', 'beforeValue', 'afterValue', 'causes'])
            ->get()
            ->map(static fn (MedicalSessionTestResult $result): MedicalSessionTestData => new MedicalSessionTestData(
                testId: (int) $result->medical_test_definition_id,
                testName: (string) $result->definition->name,
                beforeValueId: $result->before_value_id === null ? null : (int) $result->before_value_id,
                beforeLabel: $result->beforeValue?->label,
                beforeAbnormal: (bool) ($result->beforeValue?->is_abnormal ?? false),
                afterValueId: $result->after_value_id === null ? null : (int) $result->after_value_id,
                afterLabel: $result->afterValue?->label,
                afterAbnormal: (bool) ($result->afterValue?->is_abnormal ?? false),
                causes: $result->causes->map(static fn ($cause): array => [
                    'id' => (int) $cause->getKey(),
                    'name' => (string) $cause->name,
                ])->values()->all(),
            ))->all();
    }

    /** @return list<MedicalSessionRootCauseData> */
    public function rootCauses(MedicalSession $session): array
    {
        return $session->rootCauses()
            ->with('cause')
            ->get()
            ->map(fn (MedicalSessionRootCause $rootCause): MedicalSessionRootCauseData => new MedicalSessionRootCauseData(
                causeId: $rootCause->medical_cause_definition_id === null ? null : (int) $rootCause->medical_cause_definition_id,
                causeName: $rootCause->cause?->name,
                freeText: $rootCause->free_text === null
                    ? null
                    : $this->encryptor->decryptField((int) $session->organization_id, $rootCause->free_text, (int) $rootCause->encryption_key_version),
            ))->all();
    }

    /** @return list<array{id:int,name:string}> */
    public function causes(MedicalSession $session): array
    {
        return collect($this->tests($session))
            ->flatMap(static fn (MedicalSessionTestData $test): array => $test->causes)
            ->unique('id')
            ->values()
            ->all();
    }
}
