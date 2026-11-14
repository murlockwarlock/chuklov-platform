<?php

namespace App\Modules\Sessions\Application;

use App\Modules\MedicalProfiles\Domain\Contracts\MedicalEncryptorInterface;
use App\Modules\Sessions\Domain\Models\MedicalCauseDefinition;
use App\Modules\Sessions\Domain\Models\MedicalSession;
use App\Modules\Sessions\Domain\Models\MedicalSessionRootCause;
use App\Modules\Sessions\Domain\Models\MedicalSessionTestResult;
use App\Modules\Sessions\Domain\Models\MedicalTestDefinition;
use App\Modules\Sessions\Domain\Models\MedicalTestValue;
use Illuminate\Support\Collection;
use JsonException;
use Illuminate\Validation\ValidationException;

final readonly class MedicalSessionTestWriter
{
    public function __construct(
        private MedicalEncryptorInterface $encryptor,
        private MedicalClinicalTypeEngine $typeEngine,
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
        $normalizedTests = $this->normalizeTests($organizationId, $tests, $preservedTestIds, $preservedValueIds, $preservedCauseIds);
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
    }

    /**
     * @param  array<int, mixed>  $tests
     * @param  array<int, int>  $preservedTestIds
     * @param  array<int, int>  $preservedValueIds
     * @param  array<int, int>  $preservedCauseIds
     * @return array<int, array{test_id:int,before_value_id:int|null,after_value_id:int|null,cause_ids:array<int, int>,structured_payload:array<string,mixed>|null,before_violation_count:int,after_violation_count:int,review_again:bool}>
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
                    ? (int) ($before?->is_abnormal ?? false)
                    : (int) ($structuredPayload['evaluation']['before'] ?? 0),
                'after_violation_count' => $structuredPayload === null
                    ? (int) ($after?->is_abnormal ?? false)
                    : (int) ($structuredPayload['evaluation']['after'] ?? 0),
                'review_again' => (bool) ($raw['review_again'] ?? false),
            ];
        }

        return $normalized;
    }

    /** @param array<string, mixed> $raw */
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
        $causeIds = $this->structuredCauseIds($input);
        $topLevelCauseIds = collect(is_array($raw['cause_ids'] ?? null) ? $raw['cause_ids'] : [])
            ->map(static fn (mixed $id): int => (int) $id)
            ->filter(static fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();
        $causeIds = array_values(array_unique([...$causeIds, ...$topLevelCauseIds]));
        $this->validateAllowedCauses($organizationId, $definition, $causeIds, $input, $preservedCauseIds);

        $primaryCauseId = $input['primary_cause_id'] ?? ($raw['primary_cause_id'] ?? null);
        $primaryCauseId = is_numeric($primaryCauseId) && (int) $primaryCauseId > 0 ? (int) $primaryCauseId : null;
        if ($primaryCauseId !== null && ! in_array($primaryCauseId, $causeIds, true)) {
            throw ValidationException::withMessages(['test_results' => 'Основная причина должна быть выбрана среди причин строки.']);
        }

        $this->typeEngine->validate($type, $definition->catalogConfiguration(), $before, $after, $input);
        $evaluation = $this->typeEngine->evaluate($type, $definition->catalogConfiguration(), $before, $after);
        $payload = [
            'version' => 1,
            'type' => $type->value,
            'before' => $before ?? [],
            'after' => $after ?? [],
            'cause_ids' => $causeIds,
            'primary_cause_id' => $primaryCauseId,
            'correction_point' => $this->optionalString($input['correction_point'] ?? $this->nestedString($input, 'correction_point')),
            'violation_type' => $this->optionalString($input['violation_type'] ?? $this->nestedString($input, 'violation_type')),
            'review_again' => (bool) ($raw['review_again'] ?? $input['review_again'] ?? false),
            'evaluation' => [
                'before' => $evaluation->beforeCount,
                'after' => $evaluation->afterCount,
                'before_keys' => $evaluation->beforeViolationKeys,
                'after_keys' => $evaluation->afterViolationKeys,
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

    /** @param array<string, mixed> $payload
     * @return list<int>
     */
    private function structuredCauseIds(array $payload): array
    {
        $ids = [];
        $walk = function (mixed $value, ?string $key = null) use (&$walk, &$ids): void {
            if ($key !== null && in_array($key, ['cause_ids', 'primary_cause_id'], true)) {
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

    /** @param list<int> $causeIds
     * @param array<string, mixed> $input
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
            foreach (['cause_ids', 'primary_cause_id'] as $causeKey) {
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
                if (is_array($child) && ! in_array($key, ['cause_ids', 'primary_cause_id'], true)) {
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
     * @return array{cause_id:int|null,free_text:string|null,sort_order:int}
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

        return [
            'cause_id' => $causeId,
            'free_text' => $freeText === '' ? null : $freeText,
            'influences' => $influences,
            'sort_order' => $sortOrder,
            'is_primary' => filter_var($raw['is_primary'] ?? false, FILTER_VALIDATE_BOOLEAN),
        ];
    }

    /**
     * @param  array<int, mixed>  $roots
     * @param  list<int>  $availableCauseIds
     * @return array<int, array{cause_id:int|null,free_text:string|null,influences:list<int>,sort_order:int,is_primary:bool}>
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

    /** @param array{cause_id:int|null,free_text:string|null,influences:list<int>,sort_order:int,is_primary:bool} $root */
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
