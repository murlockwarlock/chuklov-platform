<?php

namespace App\Modules\Sessions\Application;

use App\Modules\Sessions\Domain\Models\MedicalCauseDefinition;
use App\Modules\Sessions\Domain\Models\MedicalTestDefinition;
use Illuminate\Database\Eloquent\Collection;

final class MedicalClinicalCatalog
{
    /** @var array<string, string> */
    private const array TestTypeLabels = [
        'T1' => 'Индекс и тонус',
        'T2' => 'Метод-компьютер',
        'T3' => 'Пальпация',
        'T4' => 'Формула и последовательность',
        'T5' => 'Да/нет и отметки',
        'T6' => 'Болевые точки',
        'T7' => 'Мышечный тест',
        'T8' => 'Вытяжение',
        'T9' => 'Подвижность и угол',
        'T10' => 'Визуальная карта',
        'T11' => 'Признаки и находки',
        'T12' => 'Энергетические структуры',
    ];

    /** @var array<string, string> */
    private const array ValueTypeLabels = [
        'normal' => 'Обычный вариант',
        'choice' => 'Вариант выбора',
        'boolean' => 'Да или нет',
        'integer' => 'Целое число',
        'decimal' => 'Число',
        'text' => 'Текст',
    ];

    /** @return array<string, string> */
    public function testTypeOptions(): array
    {
        return self::TestTypeLabels;
    }

    /** @return array<string, string> */
    public function catalogSetOptions(): array
    {
        return [
            'main' => 'Основные тесты',
            'specialized' => 'Специализированные тесты',
        ];
    }

    /** @return array<string, string> */
    public function valueTypeOptions(): array
    {
        return self::ValueTypeLabels;
    }

    /**
     * @return Collection<int, MedicalTestDefinition>
     */
    public function catalogRows(int $organizationId): Collection
    {
        return MedicalTestDefinition::query()
            ->where('organization_id', $organizationId)
            ->where(static fn ($query) => $query
                ->where('is_active', true)
                ->orWhereNotNull('test_type'))
            ->with(['values', 'causeLinks'])
            ->orderByRaw("case when catalog_set = 'main' then 0 when catalog_set = 'specialized' then 1 else 2 end")
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
    }

    /**
     * @return Collection<int, MedicalTestDefinition>
     */
    public function configurationRows(int $organizationId): Collection
    {
        return $this->catalogRows($organizationId);
    }

    /** @return array<string, string> */
    public function specializedTestOptions(int $organizationId, string $search = ''): array
    {
        $search = trim($search);

        return MedicalTestDefinition::query()
            ->where('organization_id', $organizationId)
            ->where('catalog_set', 'specialized')
            ->where('is_active', true)
            ->when($search !== '', static fn ($query) => $query->where(static fn ($query) => $query
                ->whereRaw('LOWER(name) LIKE ?', ['%'.mb_strtolower($search).'%'])
                ->orWhereRaw('LOWER(key) LIKE ?', ['%'.mb_strtolower($search).'%'])
                ->orWhereRaw('LOWER(COALESCE(section, \'\')) LIKE ?', ['%'.mb_strtolower($search).'%'])))
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get(['id', 'key', 'name'])
            ->mapWithKeys(fn (MedicalTestDefinition $test): array => [
                (int) $test->getKey() => $this->testLabel($test),
            ])
            ->all();
    }

    /** @return array<int, string> */
    public function defaultSessionSelection(int $organizationId): array
    {
        $tests = MedicalTestDefinition::query()
            ->where('organization_id', $organizationId)
            ->where('is_active', true)
            ->where('default_in_session', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get(['key', 'catalog_config']);

        if ($tests->isEmpty()) {
            return collect(MedicalStage1BCatalogPreset::defaultSelection())
                ->map(fn (string $key): string => $this->testNumber($key))
                ->all();
        }

        $selection = [];
        foreach ($tests as $test) {
            $number = $this->testNumber((string) $test->key);
            $configuration = $test->catalogConfiguration();
            $subtests = $configuration['default_subtests'] ?? [];
            if (! is_array($subtests) || $subtests === []) {
                $selection[] = $number;
                continue;
            }

            foreach ($subtests as $subtest) {
                $subtest = trim((string) $subtest);
                if ($subtest === '') {
                    continue;
                }
                $selection[] = str_starts_with($subtest, $number.'.') ? $subtest : $number.'.'.$subtest;
            }
        }

        return $selection;
    }

    /** @return list<int> */
    public function defaultSessionTestIds(int $organizationId): array
    {
        $tests = MedicalTestDefinition::query()
            ->where('organization_id', $organizationId)
            ->where('is_active', true)
            ->where('default_in_session', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->values()
            ->all();

        if ($tests !== []) {
            return $tests;
        }

        $keys = MedicalStage1BCatalogPreset::defaultSelection();

        return MedicalTestDefinition::query()
            ->where('organization_id', $organizationId)
            ->where('is_active', true)
            ->whereIn('key', $keys)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->values()
            ->all();
    }

    /** @return array<int, string> */
    public function allCauseOptions(int $organizationId): array
    {
        return MedicalCauseDefinition::query()
            ->where('organization_id', $organizationId)
            ->whereNull('merged_into_id')
            ->orderBy('is_active', 'desc')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get(['id', 'name', 'group_name', 'moderation_status', 'is_active'])
            ->mapWithKeys(static function (MedicalCauseDefinition $cause): array {
                $label = filled($cause->group_name)
                    ? $cause->group_name.': '.self::causeDisplayName((string) $cause->name)
                    : self::causeDisplayName((string) $cause->name);
                $label .= match ($cause->moderation_status) {
                    MedicalCauseDefinition::MODERATION_PENDING => ' (на модерации)',
                    MedicalCauseDefinition::MODERATION_HIDDEN => ' (скрыта)',
                    default => $cause->is_active ? '' : ' (неактивна)',
                };

                return [$cause->getKey() => $label];
            })
            ->all();
    }

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
            ->with(['values', 'causeLinks'])
            ->get([
                'id',
                'name',
                'section',
                'test_type',
                'catalog_config',
                'catalog_set',
                'default_in_session',
                'is_active',
            ]);
    }

    /** @param array<int, mixed> $results
     * @return array{before:int,after:int,resolved:int,new:int,unique_causes:int}
     */
    public function sessionCounters(int $organizationId, array $results): array
    {
        $ids = collect($results)
            ->filter(static fn (mixed $result): bool => is_array($result))
            ->map(static fn (array $result): int => (int) ($result['test_id'] ?? 0))
            ->filter(static fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();
        $definitions = MedicalTestDefinition::query()
            ->where('organization_id', $organizationId)
            ->when($ids !== [], static fn ($query) => $query->whereIn('id', $ids))
            ->get()
            ->keyBy('id');
        $engine = app(MedicalClinicalTypeEngine::class);
        $counts = ['before' => 0, 'after' => 0, 'resolved' => 0, 'new' => 0, 'unique_causes' => 0];
        $causeIds = [];

        foreach ($results as $result) {
            if (! is_array($result)) {
                continue;
            }
            $testId = (int) ($result['test_id'] ?? 0);
            $definition = $definitions->get($testId);
            if (! $definition instanceof MedicalTestDefinition) {
                continue;
            }
            $type = $definition->type();
            if ($type !== null) {
                $structured = is_array($result['structured'] ?? null)
                    ? $result['structured']
                    : (is_array($result['structured_payload'] ?? null) ? $result['structured_payload'] : []);
                $before = is_array($structured['before'] ?? null) ? $structured['before'] : null;
                $after = is_array($structured['after'] ?? null) ? $structured['after'] : null;
                $evaluation = $engine->evaluate($type, $definition->catalogConfiguration(), $before, $after);
                $counts['before'] += $evaluation->beforeCount;
                $counts['after'] += $evaluation->afterCount;
                $counts['resolved'] += $evaluation->resolvedCount;
                $counts['new'] += $evaluation->newCount;
                foreach ($evaluation->causeIds as $causeId) {
                    $causeIds[(int) $causeId] = true;
                }
                foreach (is_array($structured['cause_ids'] ?? null) ? $structured['cause_ids'] : [] as $causeId) {
                    if ((int) $causeId > 0) {
                        $causeIds[(int) $causeId] = true;
                    }
                }
                continue;
            }

            $beforeValue = $definition->values()->whereKey((int) ($result['before_value_id'] ?? 0))->first();
            $afterValue = $definition->values()->whereKey((int) ($result['after_value_id'] ?? 0))->first();
            $beforeViolation = $beforeValue?->is_abnormal === true;
            $afterViolation = $afterValue?->is_abnormal === true;
            $counts['before'] += (int) $beforeViolation;
            $counts['after'] += (int) $afterViolation;
            $counts['resolved'] += (int) ($beforeViolation && ! $afterViolation);
            $counts['new'] += (int) (!$beforeViolation && $afterViolation);
            foreach (is_array($result['cause_ids'] ?? null) ? $result['cause_ids'] : [] as $causeId) {
                if ((int) $causeId > 0) {
                    $causeIds[(int) $causeId] = true;
                }
            }
        }

        $counts['unique_causes'] = count($causeIds);

        return $counts;
    }

    /** @param array<int, mixed> $results
     * @return array{before:int,after:int}
     */
    public function abnormalCounts(int $organizationId, array $results): array
    {
        $counts = $this->sessionCounters($organizationId, $results);

        return ['before' => $counts['before'], 'after' => $counts['after']];
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
            ->where('moderation_status', MedicalCauseDefinition::MODERATION_ACTIVE)
            ->whereNull('merged_into_id')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get(['id', 'name', 'group_name'])
            ->mapWithKeys(static fn (MedicalCauseDefinition $cause): array => [
                $cause->getKey() => filled($cause->group_name)
                    ? $cause->group_name.': '.self::causeDisplayName((string) $cause->name)
                    : self::causeDisplayName((string) $cause->name),
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
            ->where(static fn ($query): mixed => $query
                ->where('is_active', false)
                ->orWhere('moderation_status', '!=', MedicalCauseDefinition::MODERATION_ACTIVE)
                ->orWhereNotNull('merged_into_id'))
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get(['id', 'name', 'group_name', 'moderation_status', 'is_active'])
            ->each(function (MedicalCauseDefinition $cause) use (&$options): void {
                $label = filled($cause->group_name)
                    ? $cause->group_name.': '.self::causeDisplayName((string) $cause->name)
                    : self::causeDisplayName((string) $cause->name);
                $status = match ($cause->moderation_status) {
                    MedicalCauseDefinition::MODERATION_PENDING => __('на модерации'),
                    MedicalCauseDefinition::MODERATION_HIDDEN => __('скрыта'),
                    default => $cause->is_active ? __('недоступна') : __('неактивна'),
                };
                $options[(int) $cause->getKey()] = $label.' ('.$status.')';
            });

        return $options;
    }

    private function testLabel(MedicalTestDefinition $test): string
    {
        $number = $this->testNumber((string) $test->key);

        return $number === '' ? (string) $test->name : 'Тест '.$number.': '.$test->name;
    }

    private function testNumber(string $key): string
    {
        if (preg_match('/_(\d+)$/', $key, $matches) !== 1) {
            return '';
        }

        return (string) ((int) $matches[1]);
    }

    public static function causeDisplayName(string $name): string
    {
        return mb_strtolower(trim($name)) === 'ии' ? 'ИИ (искусственный интеллект)' : $name;
    }
}
