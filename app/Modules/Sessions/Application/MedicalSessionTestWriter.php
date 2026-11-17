<?php

namespace App\Modules\Sessions\Application;

use App\Modules\MedicalProfiles\Domain\Contracts\MedicalEncryptorInterface;
use App\Modules\Sessions\Domain\Models\MedicalCauseDefinition;
use App\Modules\Sessions\Domain\Models\MedicalClientRootCause;
use App\Modules\Sessions\Domain\Models\MedicalSession;
use App\Modules\Sessions\Domain\Models\MedicalSessionRootCause;
use App\Modules\Sessions\Domain\Models\MedicalSessionTestResult;
use App\Modules\Sessions\Domain\Models\MedicalTestDefinition;
use App\Modules\Sessions\Domain\Models\MedicalTestValue;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use JsonException;

final readonly class MedicalSessionTestWriter
{
    public function __construct(
        private MedicalEncryptorInterface $encryptor,
        private MedicalClinicalTypeEngine $typeEngine,
        private MedicalClientRootCauseSynchronizer $clientRootCauseSynchronizer,
    ) {}

    /**
     * @param  array<int, mixed>  $tests
     * @param  array<int, mixed>  $rootCauses
     * @param  array<int, mixed>  $sessionCauseIds
     */
    public function replace(MedicalSession $session, array $tests, array $rootCauses, int $keyVersion, array $sessionCauseIds = []): void
    {
        $organizationId = (int) $session->organization_id;
        $existing = $session->testResults()->with('causes')->get()->keyBy('medical_test_definition_id');
        $preservedTestIds = $existing->keys()->map(static fn (mixed $id): int => (int) $id)->all();
        $preservedValueIds = $existing
            ->flatMap(static fn (MedicalSessionTestResult $result): array => [$result->before_value_id, $result->after_value_id])
            ->filter(static fn (mixed $id): bool => $id !== null && (int) $id > 0)
            ->map(static fn (mixed $id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
        $preservedCauseIds = $existing
            ->flatMap(static fn (MedicalSessionTestResult $result): Collection => $result->causes->pluck('id'))
            ->map(static fn (mixed $id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
        $previousSnapshots = $this->previousTestSnapshots($session);
        $normalizedTests = $this->normalizeTests($organizationId, $tests, $preservedTestIds, $preservedValueIds, $preservedCauseIds);
        $normalizedTests = $this->annotateHistory($normalizedTests, $previousSnapshots);
        $existingRootCauseIds = $session->rootCauses()
            ->whereNotNull('medical_cause_definition_id')
            ->pluck('medical_cause_definition_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();
        $testCauseIds = [];
        foreach ($normalizedTests as $test) {
            $testCauseIds = [...$testCauseIds, ...$test['cause_ids']];
        }
        $availableRootCauseIds = array_values(array_unique([
            ...$testCauseIds,
            ...$existingRootCauseIds,
            ...$this->activeCauseIds($organizationId),
            ...collect($sessionCauseIds)->map(static fn (mixed $id): int => (int) $id)->filter(static fn (int $id): bool => $id > 0)->all(),
        ]));
        $normalizedRoots = $this->normalizeRootCauses($rootCauses, $availableRootCauseIds);

        $kept = [];
        foreach ($normalizedTests as $test) {
            $definitionId = $test['test_id'];
            $result = $existing->get($definitionId) ?? new MedicalSessionTestResult;
            $result->forceFill([
                'organization_id' => $organizationId,
                'client_id' => $session->client_id,
                'medical_session_id' => $session->getKey(),
                'medical_test_definition_id' => $definitionId,
                'before_value_id' => $test['before_value_id'],
                'after_value_id' => $test['after_value_id'],
                'structured_payload' => $test['structured_payload'] === null
                    ? null
                    : $this->encryptStructuredPayload($organizationId, $test['structured_payload'], $keyVersion),
                'structured_payload_key_version' => $test['structured_payload'] === null ? null : $keyVersion,
                'before_violation_count' => $test['before_violation_count'],
                'after_violation_count' => $test['after_violation_count'],
                'review_again' => $test['review_again'],
                'is_priority' => $test['is_priority'],
                'priority_regions' => $test['priority_regions'] === []
                    ? null
                    : $this->encryptor->encryptField($organizationId, json_encode(['regions' => $test['priority_regions']], JSON_THROW_ON_ERROR), $keyVersion),
                'priority_regions_key_version' => $test['priority_regions'] === [] ? null : $keyVersion,
                'is_stabilized' => $test['is_stabilized'],
                'is_complex_case' => $test['is_complex_case'],
                'new_cause_ids' => $test['new_cause_ids'] === []
                    ? null
                    : $this->encryptor->encryptField($organizationId, json_encode(['cause_ids' => $test['new_cause_ids']], JSON_THROW_ON_ERROR), $keyVersion),
                'new_cause_ids_key_version' => $test['new_cause_ids'] === [] ? null : $keyVersion,
            ]);
            $result->save();
            $result->causes()->syncWithPivotValues($test['cause_ids'], ['organization_id' => $organizationId]);
            $kept[] = (int) $result->getKey();
        }
        if ($kept === []) {
            $session->testResults()->delete();
        } else {
            $session->testResults()->whereNotIn('id', $kept)->delete();
        }

        $session->rootCauses()->delete();
        foreach ($normalizedRoots as $root) {
            $this->persistRootCause($session, $root, $organizationId, $keyVersion);
        }
        $this->clientRootCauseSynchronizer->sync($session, $normalizedRoots, $keyVersion);
    }

    /**
     * Replace only the root-cause rows while preserving the session test results.
     *
     * @param  array<int, mixed>  $rootCauses
     * @param  array<int, mixed>  $sessionCauseIds
     */
    public function replaceRootCauses(MedicalSession $session, array $rootCauses, int $keyVersion, array $sessionCauseIds = []): void
    {
        $organizationId = (int) $session->organization_id;
        $testCauseIds = $session->testResults()
            ->with('causes')
            ->get()
            ->flatMap(static fn (MedicalSessionTestResult $result): Collection => $result->causes->pluck('id'))
            ->map(static fn (mixed $id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
        $existingRootCauseIds = $session->rootCauses()
            ->whereNotNull('medical_cause_definition_id')
            ->pluck('medical_cause_definition_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();
        $availableCauseIds = array_values(array_unique([
            ...$testCauseIds,
            ...$existingRootCauseIds,
            ...$this->activeCauseIds($organizationId),
            ...collect($sessionCauseIds)
                ->map(static fn (mixed $id): int => (int) $id)
                ->filter(static fn (int $id): bool => $id > 0)
                ->all(),
        ]));
        $normalizedRoots = $this->normalizeRootCauses($rootCauses, $availableCauseIds);

        $session->rootCauses()->delete();
        foreach ($normalizedRoots as $root) {
            $this->persistRootCause($session, $root, $organizationId, $keyVersion);
        }
        $this->clientRootCauseSynchronizer->sync($session, $normalizedRoots, $keyVersion);
    }

    /**
     * @param  array<int, mixed>  $tests
     * @param  array<int, int>  $preservedTestIds
     * @param  array<int, int>  $preservedValueIds
     * @param  array<int, int>  $preservedCauseIds
     * @return array<int, array{test_id:int,before_value_id:int|null,after_value_id:int|null,cause_ids:array<int, int>,structured_payload:array<string,mixed>|null,before_violation_count:int,after_violation_count:int,review_again:bool,is_priority:bool,priority_regions:array<int,string>,is_stabilized:bool,is_complex_case:bool,new_cause_ids:array<int,int> }>
     */
    private function normalizeTests(int $organizationId, array $tests, array $preservedTestIds, array $preservedValueIds, array $preservedCauseIds): array
    {
        $normalized = [];
        $seen = [];
        foreach (array_values($tests) as $raw) {
            if (! is_array($raw)) {
                throw ValidationException::withMessages(['test_results' => 'Проверьте строки тестов.']);
            }
            $testId = (int) ($raw['test_id'] ?? 0);
            if ($testId <= 0 || isset($seen[$testId])) {
                throw ValidationException::withMessages(['test_results' => 'Каждый тест можно добавить только один раз.']);
            }
            $definition = MedicalTestDefinition::query()->where('organization_id', $organizationId)->whereKey($testId)->first();
            if ($definition === null || (! $definition->is_active && ! in_array($testId, $preservedTestIds, true))) {
                throw ValidationException::withMessages(['test_results' => 'Выбранный тест недоступен.']);
            }
            $before = $this->value($organizationId, $definition, $raw['before_value_id'] ?? null, $preservedValueIds);
            $after = $this->value($organizationId, $definition, $raw['after_value_id'] ?? null, $preservedValueIds);
            $structuredPayload = $this->structuredPayload($organizationId, $definition, $raw, $preservedCauseIds);
            $causeIds = collect(is_array($raw['cause_ids'] ?? null) ? $raw['cause_ids'] : [])
                ->map(static fn (mixed $id): int => (int) $id)
                ->filter(static fn (int $id): bool => $id > 0)
                ->unique()
                ->values()
                ->all();
            if ($structuredPayload !== null) {
                $causeIds = array_values(array_unique([
                    ...$causeIds,
                    ...$this->structuredCauseIds($structuredPayload),
                ]));
            }
            if ($structuredPayload === null && $before === null && $after === null && $causeIds === []) {
                continue;
            }
            $hasStructuredViolation = $structuredPayload !== null
                && (($structuredPayload['evaluation']['before'] ?? 0) > 0 || ($structuredPayload['evaluation']['after'] ?? 0) > 0);
            if ($causeIds !== [] && ! $hasStructuredViolation && ! ($before !== null && $before->is_abnormal) && ! ($after !== null && $after->is_abnormal)) {
                throw ValidationException::withMessages(['test_results' => 'Причины можно указать только для теста с отклонением.']);
            }
            $causes = $causeIds === [] ? collect() : MedicalCauseDefinition::query()
                ->where('organization_id', $organizationId)
                ->whereIn('id', $causeIds)
                ->get(['id', 'is_active', 'moderation_status', 'merged_into_id']);
            if ($causes->count() !== count($causeIds) || $causes->contains(static fn (MedicalCauseDefinition $cause): bool => (
                ! $cause->is_active
                || $cause->moderation_status !== MedicalCauseDefinition::MODERATION_ACTIVE
                || $cause->merged_into_id !== null
            ) && ! in_array((int) $cause->getKey(), $preservedCauseIds, true))) {
                throw ValidationException::withMessages(['test_results' => 'Одна из причин недоступна.']);
            }
            $seen[$testId] = true;
            $normalized[] = [
                'test_id' => $testId,
                'before_value_id' => $before?->getKey(),
                'after_value_id' => $after?->getKey(),
                'cause_ids' => $causeIds,
                'structured_payload' => $structuredPayload,
                'before_violation_count' => $structuredPayload === null
                    ? (int) ($before instanceof MedicalTestValue && $before->is_abnormal)
                    : (int) ($structuredPayload['evaluation']['before'] ?? 0),
                'after_violation_count' => $structuredPayload === null
                    ? (int) ($after instanceof MedicalTestValue && $after->is_abnormal)
                    : (int) ($structuredPayload['evaluation']['after'] ?? 0),
                'review_again' => $structuredPayload !== null
                    ? (bool) ($structuredPayload['review_again'] ?? false)
                    : ((bool) ($raw['review_again'] ?? false) && $after === null),
                'is_priority' => (bool) ($raw['priority'] ?? $structuredPayload['priority'] ?? false),
                'priority_regions' => $this->stringList($raw['priority_regions'] ?? $structuredPayload['priority_regions'] ?? []),
                'is_stabilized' => false,
                'is_complex_case' => (bool) ($structuredPayload['complex_case'] ?? false),
                'new_cause_ids' => [],
            ];
        }

        return $normalized;
    }

    /** @return list<int> */
    private function activeCauseIds(int $organizationId): array
    {
        return MedicalCauseDefinition::query()
            ->where('organization_id', $organizationId)
            ->where('is_active', true)
            ->where('moderation_status', MedicalCauseDefinition::MODERATION_ACTIVE)
            ->whereNull('merged_into_id')
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->values()
            ->all();
    }

    /** @return array<int, array{after_count:int,cause_ids:list<int>,session_id:int}> */
    private function previousTestSnapshots(MedicalSession $session): array
    {
        $previous = MedicalSession::query()
            ->where('organization_id', $session->organization_id)
            ->where('client_id', $session->client_id)
            ->where('occurred_at', '<', $session->occurred_at)
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->with(['testResults.causes'])
            ->first();
        if (! $previous instanceof MedicalSession) {
            return [];
        }

        $snapshots = [];
        foreach ($previous->testResults as $result) {
            $payload = $this->decryptStructuredPayload($previous, $result);
            $snapshots[(int) $result->medical_test_definition_id] = [
                'after_count' => (int) ($result->after_violation_count ?? 0),
                'cause_ids' => array_values(array_unique($result->causes->pluck('id')->map('intval')->all())),
                'session_id' => (int) $previous->getKey(),
                'payload' => $payload,
            ];
        }

        return $snapshots;
    }

    /**
     * @param  array<int, array<string, mixed>>  $tests
     * @param  array<int, array<string, mixed>>  $snapshots
     * @return array<int, array<string, mixed>>
     */
    private function annotateHistory(array $tests, array $snapshots): array
    {
        foreach ($tests as &$test) {
            $previous = $snapshots[$test['test_id']] ?? null;
            if (! is_array($previous)) {
                continue;
            }
            if ($previous['after_count'] > 0 && $test['before_violation_count'] === 0) {
                $test['is_stabilized'] = true;
                if (is_array($test['structured_payload'])) {
                    $test['structured_payload']['stabilization'] = [
                        'is_stabilized' => true,
                        'previous_session_id' => $previous['session_id'],
                        'previous_after_count' => $previous['after_count'],
                    ];
                }
            }
            $newCauseIds = array_values(array_diff(
                $test['cause_ids'],
                is_array($previous['cause_ids'] ?? null) ? $previous['cause_ids'] : [],
            ));
            if ($newCauseIds !== []) {
                $test['new_cause_ids'] = $newCauseIds;
                if (is_array($test['structured_payload'])) {
                    $test['structured_payload']['new_cause_ids'] = $newCauseIds;
                }
            }
        }
        unset($test);

        return $tests;
    }

    /** @return array<string, mixed>|null */
    private function decryptStructuredPayload(MedicalSession $session, MedicalSessionTestResult $result): ?array
    {
        if ($result->structured_payload === null) {
            return null;
        }
        $decoded = json_decode((string) $this->encryptor->decryptField(
            (int) $session->organization_id,
            $result->structured_payload,
            (int) ($result->structured_payload_key_version ?? $session->encryption_key_version),
        ), true, 12);

        return is_array($decoded) ? $decoded : null;
    }

    /** @return list<string> */
    private function stringList(mixed $value): array
    {
        if (is_string($value)) {
            $value = preg_split('/[,;\n]+/u', $value) ?: [];
        }
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_unique(array_filter(array_map(
            static fn (mixed $item): string => trim((string) $item),
            $value,
        ), static fn (string $item): bool => $item !== '')));
    }

    /**
     * @param  array<string, mixed>  $raw
     * @param  list<int>  $preservedCauseIds
     * @return array<string, mixed>|null
     */
    private function structuredPayload(int $organizationId, MedicalTestDefinition $definition, array $raw, array $preservedCauseIds = []): ?array
    {
        $hasPayload = array_key_exists('structured', $raw) || array_key_exists('structured_payload', $raw);
        if (! $hasPayload) {
            return null;
        }

        $input = $raw['structured'] ?? $raw['structured_payload'];
        if (! is_array($input)) {
            throw ValidationException::withMessages(['test_results' => 'Структурированный результат теста заполнен неверно.']);
        }

        $type = $definition->type();
        if ($type === null || ! $this->typeEngine->supports($type)) {
            throw ValidationException::withMessages(['test_results' => 'Для выбранного теста не настроен типизированный механизм.']);
        }

        $before = $this->structuredPhase($input['before'] ?? null);
        $after = $this->structuredPhase($input['after'] ?? null);
        $generalIndexes = $this->generalIndexes($input);
        $causeIds = $this->structuredCauseIds($input);
        $topLevelCauseIds = collect(is_array($raw['cause_ids'] ?? null) ? $raw['cause_ids'] : [])
            ->map(static fn (mixed $id): int => (int) $id)
            ->filter(static fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();
        $causeIds = array_values(array_unique([...$causeIds, ...$topLevelCauseIds]));
        $this->validateAllowedCauses($organizationId, $definition, $causeIds, $input, $preservedCauseIds);

        $primaryCauseIds = $this->primaryCauseIds($input, $raw);
        if (array_diff($primaryCauseIds, $causeIds) !== []) {
            throw ValidationException::withMessages(['test_results' => 'Основные причины должны быть выбраны среди причин строки.']);
        }

        $this->typeEngine->validate($type, $definition->catalogConfiguration(), $before, $after, $input);
        $this->validateCauseLimits($definition, $before, $after);
        $evaluation = $this->typeEngine->evaluate($type, $definition->catalogConfiguration(), $before, $after, $generalIndexes);
        $complexCaseKeys = $this->complexCaseKeys($before, $after);
        if ($type === \App\Modules\Sessions\Domain\Enums\MedicalTestType::T4) {
            $complexCaseKeys = array_values(array_unique([
                ...$complexCaseKeys,
                ...$this->myozonalComplexCaseKeys($before, $after),
            ]));
        }
        $priorityRegions = $this->stringList($raw['priority_regions'] ?? $input['priority_regions'] ?? []);
        $payload = [
            'version' => 1,
            'type' => $type->value,
            'before' => $before ?? [],
            'after' => $after ?? [],
            'general_index' => $generalIndexes,
            'cause_ids' => $causeIds,
            'primary_cause_ids' => $primaryCauseIds,
            'primary_cause_id' => $primaryCauseIds[0] ?? null,
            'priority' => (bool) ($raw['priority'] ?? $input['priority'] ?? false),
            'priority_regions' => $priorityRegions,
            'complex_case' => $complexCaseKeys !== [],
            'complex_case_keys' => $complexCaseKeys,
            'correction_point' => $this->optionalString($input['correction_point'] ?? $this->nestedString($input, 'correction_point')),
            'violation_type' => $this->optionalString($input['violation_type'] ?? $this->nestedString($input, 'violation_type')),
            'review_again' => (bool) ($raw['review_again'] ?? $input['review_again'] ?? false)
                && ! $this->hasStructuredMeasurement($after),
            'evaluation' => [
                'before' => $evaluation->beforeCount,
                'after' => $evaluation->afterCount,
                'before_keys' => $evaluation->beforeViolationKeys,
                'after_keys' => $evaluation->afterViolationKeys,
                'linked_segments' => $evaluation->linkedSegments,
                'warnings' => $evaluation->warnings,
                'fail_closed' => $evaluation->failClosed,
            ],
        ];

        try {
            $encoded = json_encode($payload, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw ValidationException::withMessages(['test_results' => 'Структурированный результат содержит неподдерживаемые данные.']);
        }
        if (strlen($encoded) > 250000) {
            throw ValidationException::withMessages(['test_results' => 'Структурированный результат слишком большой.']);
        }

        return $payload;
    }

    /** @return array<string, mixed>|null */
    private function structuredPhase(mixed $phase): ?array
    {
        if ($phase === null || $phase === '') {
            return null;
        }
        if (! is_array($phase)) {
            throw ValidationException::withMessages(['test_results' => 'Фаза результата теста заполнена неверно.']);
        }

        try {
            json_encode($phase, JSON_THROW_ON_ERROR, 12);
        } catch (JsonException) {
            throw ValidationException::withMessages(['test_results' => 'Фаза результата содержит неподдерживаемые данные.']);
        }

        return $phase;
    }

    /** @param array<string, mixed> $input
     * @return array<string, int>|null
     */
    private function generalIndexes(array $input): ?array
    {
        if (! array_key_exists('general_index', $input)) {
            return null;
        }
        $value = $input['general_index'];
        if (! is_array($value)) {
            throw ValidationException::withMessages(['test_results' => 'Общий индекс заполнен неверно.']);
        }

        return [
            'before' => $this->nullableIndex($value['before'] ?? null),
            'after' => $this->nullableIndex($value['after'] ?? null),
        ];
    }

    private function nullableIndex(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (! is_numeric($value) || (float) $value !== (float) (int) $value || (int) $value < 0 || (int) $value > 3) {
            throw ValidationException::withMessages(['test_results' => 'Общий индекс должен быть от 0 до 3.']);
        }

        return (int) $value;
    }

    /** @param array<string, mixed>|null $phase */
    private function hasStructuredMeasurement(?array $phase): bool
    {
        if ($phase === null) {
            return false;
        }

        foreach (['index', 'tone', 'finding', 'answer', 'deviation', 'angle', 'pain_point', 'energy', 'tor_direction', 'method', 'movement', 'movement_phase', 'unit', 'measurement', 'measurement_value', 'degrees', 'pain_intensity', 'tl_reduction_percent'] as $field) {
            if (! array_key_exists($field, $phase)) {
                continue;
            }
            $value = $phase[$field];
            if ($value !== null && $value !== '') {
                return true;
            }
        }
        if (($phase['marked'] ?? false) === true) {
            return true;
        }
        if (is_array($phase['sequence'] ?? null)) {
            foreach ($phase['sequence'] as $item) {
                $value = is_array($item) ? ($item['value'] ?? null) : $item;
                if ($value !== null && $value !== '') {
                    return true;
                }
            }
        }

        foreach (['rows', 'feelings', 'subtests', 'provoking_points'] as $field) {
            if (! is_array($phase[$field] ?? null)) {
                continue;
            }
            foreach ($phase[$field] as $child) {
                if (is_array($child) && $this->hasStructuredMeasurement($child)) {
                    return true;
                }
            }
        }

        return false;
    }

    /** @param array<string, mixed> $payload
     * @return list<int>
     */
    private function structuredCauseIds(array $payload): array
    {
        $ids = [];
        $walk = function (mixed $value, ?string $key = null) use (&$walk, &$ids): void {
            if ($key !== null && in_array($key, ['cause_ids', 'primary_cause_id', 'primary_cause_ids'], true)) {
                foreach (is_array($value) ? $value : [$value] as $id) {
                    if (is_numeric($id) && (int) $id > 0) {
                        $ids[] = (int) $id;
                    }
                }

                return;
            }
            if (is_array($value)) {
                foreach ($value as $childKey => $child) {
                    $walk($child, is_string($childKey) ? $childKey : null);
                }
            }
        };
        $walk($payload);

        return array_values(array_unique($ids));
    }

    /** @param array<string, mixed> $input
     * @param array<string, mixed> $raw
     * @return list<int>
     */
    private function primaryCauseIds(array $input, array $raw): array
    {
        $value = $input['primary_cause_ids'] ?? $raw['primary_cause_ids'] ?? null;
        if ($value === null) {
            $value = $input['primary_cause_id'] ?? $raw['primary_cause_id'] ?? null;
        }
        if (! is_array($value)) {
            $value = $value === null || $value === '' ? [] : [$value];
        }

        return array_values(array_unique(array_filter(array_map('intval', $value), static fn (int $id): bool => $id > 0)));
    }

    private function validateCauseLimits(MedicalTestDefinition $definition, ?array $before, ?array $after): void
    {
        foreach ([['phase' => $before, 'name' => 'before'], ['phase' => $after, 'name' => 'after']] as $item) {
            foreach ($this->causeRows($item['phase']) as $row) {
                $primary = $this->primaryCauseIds($row, []);
                if (count($primary) > 6) {
                    throw ValidationException::withMessages(['test_results' => 'Для одного региона можно указать не более шести главных причин.']);
                }
            }
        }

        if ($definition->type() !== \App\Modules\Sessions\Domain\Enums\MedicalTestType::T4) {
            return;
        }
        $rules = $definition->catalogConfiguration()['rules'] ?? [];
        $maxRegions = is_array($rules) && is_numeric($rules['myozonal_max_regions'] ?? null)
            ? (int) $rules['myozonal_max_regions']
            : null;
        if ($maxRegions === null) {
            return;
        }
        $regions = [];
        foreach ([$before, $after] as $phase) {
            foreach ($this->findingRows($phase) as $row) {
                if ((int) ($row['index'] ?? 0) > 0) {
                    $regions[$this->rowKey($row)] = true;
                }
            }
        }
        if (count($regions) > $maxRegions) {
            throw ValidationException::withMessages(['test_results' => 'Для миозонального теста можно отметить не более двух зон вне нормы.']);
        }
    }

    /** @param array<string, mixed>|null $phase
     * @return list<array<string, mixed>>
     */
    private function causeRows(?array $phase): array
    {
        $rows = [];
        $walk = function (mixed $value) use (&$walk, &$rows): void {
            if (! is_array($value)) {
                return;
            }
            if (array_key_exists('cause_ids', $value) || array_key_exists('primary_cause_id', $value) || array_key_exists('primary_cause_ids', $value)) {
                $rows[] = $value;
            }
            foreach ($value as $child) {
                if (is_array($child)) {
                    $walk($child);
                }
            }
        };
        $walk($phase);

        return $rows;
    }

    /** @param array<string, mixed>|null $before
     * @param array<string, mixed>|null $after
     * @return list<string>
     */
    private function complexCaseKeys(?array $before, ?array $after): array
    {
        $keys = [];
        foreach ([$before, $after] as $phase) {
            foreach ($this->causeRows($phase) as $row) {
                $ids = $this->structuredCauseIds($row);
                if (count($ids) >= 3) {
                    $keys[$this->rowKey($row)] = true;
                }
            }
        }

        return array_keys($keys);
    }

    /** @param array<string, mixed>|null $before
     * @param array<string, mixed>|null $after
     */
    private function complexCase(?array $before, ?array $after): bool
    {
        return $this->complexCaseKeys($before, $after) !== [];
    }

    /** @param array<string, mixed> $row */
    private function rowKey(array $row): string
    {
        $key = trim((string) ($row['dedupe_key'] ?? $row['key'] ?? 'row'));
        $side = trim((string) ($row['side'] ?? ''));

        return $key.($side === '' ? '' : ':'.$side);
    }

    /** @param array<string, mixed>|null $before
     * @param array<string, mixed>|null $after
     * @return list<string>
     */
    private function myozonalComplexCaseKeys(?array $before, ?array $after): array
    {
        $keys = [];
        foreach ([$before, $after] as $phase) {
            foreach ($this->findingRows($phase) as $row) {
                if ((int) ($row['index'] ?? 0) > 0) {
                    $keys[$this->rowKey($row)] = true;
                }
            }
        }

        return count($keys) >= 2 ? array_map(static fn (string $key): string => 'myozonal:'.$key, array_keys($keys)) : [];
    }

    /** @param list<int> $causeIds
     * @param  array<string, mixed>  $input
     * @param  list<int>  $preservedCauseIds
     */
    private function validateAllowedCauses(int $organizationId, MedicalTestDefinition $definition, array $causeIds, array $input, array $preservedCauseIds = []): void
    {
        if ($causeIds === []) {
            return;
        }

        $links = $definition->causeLinks()
            ->where('organization_id', $organizationId)
            ->where('is_active', true)
            ->get(['medical_cause_definition_id', 'scope_key']);
        if ($links->isEmpty()) {
            if ($definition->type() !== null && array_diff($causeIds, $preservedCauseIds) !== []) {
                throw ValidationException::withMessages(['test_results' => 'Для этого теста причины ещё не настроены.']);
            }

            return;
        }
        $global = $links
            ->filter(static fn ($link): bool => $link->scope_key === null)
            ->map(static fn ($link): int => (int) $link->medical_cause_definition_id)
            ->all();
        $scoped = $links
            ->filter(static fn ($link): bool => $link->scope_key !== null)
            ->groupBy('scope_key')
            ->map(static fn ($rows): array => $rows->map(static fn ($link): int => (int) $link->medical_cause_definition_id)->all())
            ->all();
        $invalid = [];
        $walk = function (mixed $value, ?string $scope = null) use (&$walk, &$invalid, $global, $scoped, $preservedCauseIds): void {
            if (! is_array($value)) {
                return;
            }
            $currentScope = is_string($value['key'] ?? null) && trim($value['key']) !== ''
                ? trim($value['key'])
                : $scope;
            foreach (['cause_ids', 'primary_cause_id', 'primary_cause_ids'] as $causeKey) {
                if (! array_key_exists($causeKey, $value)) {
                    continue;
                }
                $allowed = [...$global, ...($currentScope !== null ? ($scoped[$currentScope] ?? []) : [])];
                foreach (is_array($value[$causeKey]) ? $value[$causeKey] : [$value[$causeKey]] as $causeId) {
                    if (is_numeric($causeId)
                        && (int) $causeId > 0
                        && ! in_array((int) $causeId, $allowed, true)
                        && ! in_array((int) $causeId, $preservedCauseIds, true)) {
                        $invalid[] = (int) $causeId;
                    }
                }
            }
            foreach ($value as $key => $child) {
                if (is_array($child) && ! in_array($key, ['cause_ids', 'primary_cause_id', 'primary_cause_ids'], true)) {
                    $walk($child, $currentScope);
                }
            }
        };
        $walk($input);
        if ($invalid !== []) {
            throw ValidationException::withMessages(['test_results' => 'Одна из причин не разрешена для выбранной строки теста.']);
        }
    }

    private function optionalString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : mb_substr($value, 0, 1000);
    }

    /** @param array<string, mixed> $payload */
    private function nestedString(array $payload, string $key): ?string
    {
        foreach ($payload as $value) {
            if (! is_array($value)) {
                continue;
            }
            if (is_string($value[$key] ?? null) && trim($value[$key]) !== '') {
                return $value[$key];
            }
            $nested = $this->nestedString($value, $key);
            if ($nested !== null) {
                return $nested;
            }
        }

        return null;
    }

    /** @param array<string, mixed> $payload */
    private function encryptStructuredPayload(int $organizationId, array $payload, int $keyVersion): ?string
    {
        try {
            return $this->encryptor->encryptField($organizationId, json_encode($payload, JSON_THROW_ON_ERROR), $keyVersion);
        } catch (JsonException) {
            throw ValidationException::withMessages(['test_results' => 'Не удалось подготовить структурированный результат.']);
        }
    }

    /**
     * @param  list<int>  $availableCauseIds
     * @return array{cause_id:int|null,free_text:string|null,influences:list<int>,region:string|null,status:string|null,sort_order:int,is_primary:bool}
     */
    private function normalizeRootCause(mixed $raw, int $sortOrder, array $availableCauseIds): array
    {
        if (! is_array($raw)) {
            throw ValidationException::withMessages(['root_causes' => 'Проверьте первопричины.']);
        }
        $causeId = (int) ($raw['cause_id'] ?? 0);
        $causeId = $causeId > 0 ? $causeId : null;
        if ($causeId !== null && ! in_array($causeId, $availableCauseIds, true)) {
            throw ValidationException::withMessages(['root_causes' => 'Выбранная первопричина недоступна.']);
        }
        $freeTextInput = $raw['free_text'] ?? null;
        if ($freeTextInput !== null && ! is_string($freeTextInput)) {
            throw ValidationException::withMessages(['root_causes' => 'Описание первопричины должно быть текстом.']);
        }
        $freeText = trim((string) ($freeTextInput ?? ''));
        if ($causeId === null && $freeText === '') {
            throw ValidationException::withMessages(['root_causes' => 'Выберите причину или укажите описание.']);
        }
        if ($freeTextInput !== null && mb_strlen($freeTextInput) > MedicalSessionFieldLimits::MaxTextLength) {
            throw ValidationException::withMessages([
                'root_causes' => 'Описание первопричины не может быть длиннее '.MedicalSessionFieldLimits::MaxTextLength.' символов.',
            ]);
        }

        $influences = collect(is_array($raw['influences'] ?? null) ? $raw['influences'] : [])
            ->map(static fn (mixed $id): int => (int) $id)
            ->filter(static fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();
        if (array_diff($influences, $availableCauseIds) !== []) {
            throw ValidationException::withMessages(['root_causes' => 'Связь «На это влияет» содержит недоступную причину.']);
        }
        $regionInput = $raw['region'] ?? null;
        if ($regionInput !== null && ! is_string($regionInput)) {
            throw ValidationException::withMessages(['root_causes' => 'Регион первопричины должен быть текстом.']);
        }
        $region = is_string($regionInput) ? trim($regionInput) : '';
        if (mb_strlen($region) > 255) {
            throw ValidationException::withMessages(['root_causes' => 'Регион первопричины слишком длинный.']);
        }
        $status = array_key_exists('status', $raw) ? trim((string) $raw['status']) : null;
        if ($status !== null && ! in_array($status, [MedicalClientRootCause::STATUS_NEW, MedicalClientRootCause::STATUS_IN_PROGRESS, MedicalClientRootCause::STATUS_RESOLVED], true)) {
            throw ValidationException::withMessages(['root_causes' => 'Статус первопричины выбран неверно.']);
        }

        return [
            'cause_id' => $causeId,
            'free_text' => $freeText === '' ? null : $freeText,
            'influences' => $influences,
            'region' => $region === '' ? null : $region,
            'status' => $status,
            'sort_order' => $sortOrder,
            'is_primary' => filter_var($raw['is_primary'] ?? false, FILTER_VALIDATE_BOOLEAN),
        ];
    }

    /**
     * @param  array<int, mixed>  $roots
     * @param  list<int>  $availableCauseIds
     * @return array<int, array{cause_id:int|null,free_text:string|null,influences:list<int>,region:string|null,status:string|null,sort_order:int,is_primary:bool}>
     */
    private function normalizeRootCauses(array $roots, array $availableCauseIds): array
    {
        if (count($roots) > 20) {
            throw ValidationException::withMessages(['root_causes' => 'Можно выбрать не более двадцати первопричин.']);
        }

        $normalized = array_map(fn (mixed $raw, int $index): array => $this->normalizeRootCause($raw, $index, $availableCauseIds), array_values($roots), array_keys(array_values($roots)));
        $primaryIndexes = array_keys(array_filter($normalized, static fn (array $root): bool => $root['is_primary']));
        if (count($primaryIndexes) > 1) {
            throw ValidationException::withMessages(['root_causes' => 'Можно выбрать только одну главную первопричину.']);
        }
        if ($normalized !== [] && $primaryIndexes === []) {
            $normalized[0]['is_primary'] = true;
        }

        return $normalized;
    }

    /** @param array{cause_id:int|null,free_text:string|null,influences:list<int>,region:string|null,status:string|null,sort_order:int,is_primary:bool} $root */
    private function persistRootCause(MedicalSession $session, array $root, int $organizationId, int $keyVersion): void
    {
        $model = new MedicalSessionRootCause;
        $model->forceFill([
            'organization_id' => $organizationId,
            'client_id' => $session->client_id,
            'medical_session_id' => $session->getKey(),
            'medical_cause_definition_id' => $root['cause_id'],
            'free_text' => $this->encryptor->encryptField($organizationId, $root['free_text'], $keyVersion),
            'encryption_key_version' => $keyVersion,
            'is_primary' => $root['is_primary'],
            'status' => $root['status'] ?? MedicalClientRootCause::STATUS_NEW,
            'region' => $root['region'] === null ? null : $this->encryptor->encryptField($organizationId, $root['region'], $keyVersion),
            'region_key_version' => $root['region'] === null ? null : $keyVersion,
            'influences' => $root['influences'] === []
                ? null
                : $this->encryptor->encryptField($organizationId, json_encode(['cause_ids' => $root['influences']], JSON_THROW_ON_ERROR), $keyVersion),
            'sort_order' => $root['sort_order'],
        ]);
        $model->save();
    }

    /** @param array<int, int> $preservedValueIds */
    private function value(int $organizationId, MedicalTestDefinition $definition, mixed $valueId, array $preservedValueIds): ?MedicalTestValue
    {
        if ($valueId === null || $valueId === '') {
            return null;
        }
        $value = MedicalTestValue::query()
            ->where('organization_id', $organizationId)
            ->where('medical_test_definition_id', $definition->getKey())
            ->whereKey((int) $valueId)
            ->first();
        if ($value === null || (! $value->is_active && ! in_array((int) $value->getKey(), $preservedValueIds, true))) {
            throw ValidationException::withMessages(['test_results' => 'Выбранное значение теста недоступно.']);
        }

        return $value;
    }
}
