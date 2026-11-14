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
            ->map(fn (MedicalSessionTestResult $result): MedicalSessionTestData => new MedicalSessionTestData(
                testId: (int) $result->medical_test_definition_id,
                testName: self::testName($result),
                beforeValueId: $result->before_value_id === null ? null : (int) $result->before_value_id,
                beforeLabel: $result->beforeValue?->label,
                beforeAbnormal: $this->beforeAbnormal($result),
                afterValueId: $result->after_value_id === null ? null : (int) $result->after_value_id,
                afterLabel: $result->afterValue?->label,
                afterAbnormal: $this->afterAbnormal($result),
                causes: array_values($result->causes->map(static fn ($cause): array => [
                    'id' => (int) $cause->getKey(),
                    'name' => MedicalClinicalCatalog::causeDisplayName((string) $cause->name),
                ])->values()->all()),
                structuredPayload: $this->structuredPayload($session, $result),
                beforeViolationCount: (int) ($result->before_violation_count ?? 0),
                afterViolationCount: (int) ($result->after_violation_count ?? 0),
                reviewAgain: (bool) ($result->review_again ?? false),
                warnings: $this->structuredWarnings($session, $result),
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
                causeName: $rootCause->cause === null
                    ? null
                    : MedicalClinicalCatalog::causeDisplayName((string) $rootCause->cause->name),
                freeText: $rootCause->free_text === null
                    ? null
                    : $this->encryptor->decryptField((int) $session->organization_id, $rootCause->free_text, (int) $rootCause->encryption_key_version),
                isPrimary: (bool) $rootCause->is_primary,
                influences: $this->influences($session, $rootCause),
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
                    'name' => MedicalClinicalCatalog::causeDisplayName((string) $cause->name),
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

    private function beforeAbnormal(MedicalSessionTestResult $result): bool
    {
        return (int) ($result->before_violation_count ?? 0) > 0
            || (bool) ($result->beforeValue && $result->beforeValue->is_abnormal);
    }

    private function afterAbnormal(MedicalSessionTestResult $result): bool
    {
        return (int) ($result->after_violation_count ?? 0) > 0
            || (bool) ($result->afterValue && $result->afterValue->is_abnormal);
    }

    /** @return array<string, mixed>|null */
    private function structuredPayload(MedicalSession $session, MedicalSessionTestResult $result): ?array
    {
        if ($result->structured_payload === null) {
            return null;
        }

        $decoded = json_decode(
            (string) $this->encryptor->decryptField(
                (int) $session->organization_id,
                $result->structured_payload,
                (int) ($result->structured_payload_key_version ?? $session->encryption_key_version),
            ),
            true,
            12,
        );

        return is_array($decoded) ? $decoded : null;
    }

    /** @return list<string> */
    private function structuredWarnings(MedicalSession $session, MedicalSessionTestResult $result): array
    {
        $payload = $this->structuredPayload($session, $result);
        $warnings = $payload['evaluation']['warnings'] ?? [];

        return is_array($warnings) ? array_values(array_filter($warnings, 'is_string')) : [];
    }

    /** @return list<int> */
    private function influences(MedicalSession $session, MedicalSessionRootCause $rootCause): array
    {
        if ($rootCause->influences === null) {
            return [];
        }
        $decoded = json_decode((string) $this->encryptor->decryptField(
            (int) $session->organization_id,
            $rootCause->influences,
            (int) $rootCause->encryption_key_version,
        ), true);
        if (! is_array($decoded) || ! is_array($decoded['cause_ids'] ?? null)) {
            return [];
        }

        return array_values(array_unique(array_filter(array_map('intval', $decoded['cause_ids']), static fn (int $id): bool => $id > 0)));
    }
}
