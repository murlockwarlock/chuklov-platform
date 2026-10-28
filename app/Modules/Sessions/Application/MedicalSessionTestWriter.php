<?php

namespace App\Modules\Sessions\Application;

use App\Modules\MedicalProfiles\Domain\Contracts\MedicalEncryptorInterface;
use App\Modules\Sessions\Domain\Models\MedicalCauseDefinition;
use App\Modules\Sessions\Domain\Models\MedicalSession;
use App\Modules\Sessions\Domain\Models\MedicalSessionRootCause;
use App\Modules\Sessions\Domain\Models\MedicalSessionTestResult;
use App\Modules\Sessions\Domain\Models\MedicalTestDefinition;
use App\Modules\Sessions\Domain\Models\MedicalTestValue;
use Illuminate\Validation\ValidationException;

final readonly class MedicalSessionTestWriter
{
    public function __construct(private MedicalEncryptorInterface $encryptor) {}

    /**
     * @param  array<int, mixed>  $tests
     * @param  array<int, mixed>  $rootCauses
     */
    public function replace(MedicalSession $session, array $tests, array $rootCauses, int $keyVersion): void
    {
        $organizationId = (int) $session->organization_id;
        $normalizedTests = $this->normalizeTests($organizationId, $tests);
        $availableRootCauseIds = array_values(array_unique(array_merge(
            ...array_map(static fn (array $test): array => $test['cause_ids'], $normalizedTests),
        )));
        $normalizedRoots = $this->normalizeRootCauses($rootCauses, $availableRootCauseIds);

        $existing = $session->testResults()->get()->keyBy('medical_test_definition_id');
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
            $model = new MedicalSessionRootCause;
            $model->forceFill([
                'organization_id' => $organizationId,
                'client_id' => $session->client_id,
                'medical_session_id' => $session->getKey(),
                'medical_cause_definition_id' => $root['cause_id'],
                'free_text' => $this->encryptor->encryptField($organizationId, $root['free_text'], $keyVersion),
                'encryption_key_version' => $keyVersion,
                'sort_order' => $root['sort_order'],
            ]);
            $model->save();
        }
    }

    /**
     * @param  array<int, mixed>  $tests
     * @return array<int, array{test_id:int,before_value_id:int|null,after_value_id:int|null,cause_ids:array<int, int>}>
     */
    private function normalizeTests(int $organizationId, array $tests): array
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
            if ($definition === null) {
                throw ValidationException::withMessages(['test_results' => 'Выбранный тест недоступен.']);
            }
            $before = $this->value($organizationId, $definition, $raw['before_value_id'] ?? null);
            $after = $this->value($organizationId, $definition, $raw['after_value_id'] ?? null);
            $causeIds = collect(is_array($raw['cause_ids'] ?? null) ? $raw['cause_ids'] : [])
                ->map(static fn (mixed $id): int => (int) $id)
                ->filter(static fn (int $id): bool => $id > 0)
                ->unique()
                ->values()
                ->all();
            if ($causeIds !== [] && ! ($before !== null && $before->is_abnormal) && ! ($after !== null && $after->is_abnormal)) {
                throw ValidationException::withMessages(['test_results' => 'Причины можно указать только для теста с отклонением.']);
            }
            if ($causeIds !== [] && MedicalCauseDefinition::query()->where('organization_id', $organizationId)->whereIn('id', $causeIds)->count() !== count($causeIds)) {
                throw ValidationException::withMessages(['test_results' => 'Одна из причин недоступна.']);
            }
            $seen[$testId] = true;
            $normalized[] = ['test_id' => $testId, 'before_value_id' => $before?->getKey(), 'after_value_id' => $after?->getKey(), 'cause_ids' => $causeIds];
        }

        return $normalized;
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
        $freeText = trim((string) ($raw['free_text'] ?? ''));
        if ($causeId === null && $freeText === '') {
            throw ValidationException::withMessages(['root_causes' => 'Выберите причину или укажите описание.']);
        }

        return ['cause_id' => $causeId, 'free_text' => $freeText === '' ? null : $freeText, 'sort_order' => $sortOrder];
    }

    /**
     * @param  array<int, mixed>  $roots
     * @param  list<int>  $availableCauseIds
     * @return array<int, array{cause_id:int|null,free_text:string|null,sort_order:int}>
     */
    private function normalizeRootCauses(array $roots, array $availableCauseIds): array
    {
        if (count($roots) > 2) {
            throw ValidationException::withMessages(['root_causes' => 'Можно выбрать не более двух первопричин.']);
        }

        return array_map(fn (mixed $raw, int $index): array => $this->normalizeRootCause($raw, $index, $availableCauseIds), array_values($roots), array_keys(array_values($roots)));
    }

    private function value(int $organizationId, MedicalTestDefinition $definition, mixed $valueId): ?MedicalTestValue
    {
        if ($valueId === null || $valueId === '') {
            return null;
        }
        $value = MedicalTestValue::query()
            ->where('organization_id', $organizationId)
            ->where('medical_test_definition_id', $definition->getKey())
            ->whereKey((int) $valueId)
            ->first();
        if ($value === null) {
            throw ValidationException::withMessages(['test_results' => 'Выбранное значение теста недоступно.']);
        }

        return $value;
    }
}
