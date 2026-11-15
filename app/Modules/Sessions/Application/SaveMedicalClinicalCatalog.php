<?php

namespace App\Modules\Sessions\Application;

use App\Models\User;
use App\Modules\MedicalProfiles\Domain\Models\MedicalComplaintDefinition;
use App\Modules\MedicalProfiles\Domain\Models\MedicalProblemTag;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use App\Modules\Security\Application\RecordAuditEvent;
use App\Modules\Sessions\Domain\Enums\MedicalTestType;
use App\Modules\Sessions\Domain\Models\MedicalCauseDefinition;
use App\Modules\Sessions\Domain\Models\MedicalTestCauseLink;
use App\Modules\Sessions\Domain\Models\MedicalTestDefinition;
use App\Modules\Sessions\Domain\Models\MedicalTestValue;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final readonly class SaveMedicalClinicalCatalog
{
    public function __construct(
        private OrganizationContext $context,
        private OrganizationAuthorizer $authorizer,
        private RecordAuditEvent $audit,
    ) {}

    /**
     * @param  array<int, mixed>  $tests
     * @param  array<int, mixed>  $causes
     * @param  array<int, mixed>|null  $problemTags
     * @param  array<int, mixed>|null  $complaints
     */
    public function handle(User $actor, array $tests, array $causes, ?array $problemTags = null, ?array $complaints = null): void
    {
        $organization = $this->context->organization();
        if (! $this->authorizer->allows($actor, $organization, OrganizationPermission::ManageSettings)) {
            throw ValidationException::withMessages(['catalog' => 'Недостаточно прав для изменения справочника.']);
        }

        $normalizedTests = $this->normalizeTests($tests);
        $normalizedCauses = $this->normalizeCauses($causes);
        $normalizedProblemTags = $problemTags === null ? null : $this->normalizeProblemTags($problemTags);
        $normalizedComplaints = $complaints === null ? null : $this->normalizeComplaints($complaints);
        $organizationId = (int) $organization->getKey();

        DB::transaction(function () use ($organizationId, $normalizedTests, $normalizedCauses, $normalizedProblemTags, $normalizedComplaints, $organization, $actor): void {
            /** @var Collection<int, MedicalCauseDefinition> $lockedCauses */
            $lockedCauses = MedicalCauseDefinition::query()
                ->where('organization_id', $organizationId)
                ->whereNull('merged_into_id')
                ->lockForUpdate()
                ->get();
            $causeNames = [];
            $causeIdentities = [];
            foreach ($normalizedCauses as $causeIndex => $cause) {
                $owner = $cause['id'] === null ? 'new:'.$causeIndex : (string) $cause['id'];
                foreach ($cause['identities'] as $identity) {
                    if (isset($causeIdentities[$identity]) && $causeIdentities[$identity] !== $owner) {
                        throw ValidationException::withMessages(['causes' => 'Названия причин и синонимы должны быть уникальны.']);
                    }
                    $causeIdentities[$identity] = $owner;
                }

                $modelQuery = MedicalCauseDefinition::query()->where('organization_id', $organizationId);
                if ($cause['id'] !== null) {
                    $modelQuery->whereKey($cause['id']);
                } else {
                    $modelQuery->where('name', $cause['name']);
                }
                $model = $modelQuery->lockForUpdate()->first();
                if ($cause['id'] !== null && $model === null) {
                    throw ValidationException::withMessages(['causes' => 'Выбранная причина не найдена в этой организации.']);
                }
                $model ??= new MedicalCauseDefinition;
                if ($model->merged_into_id !== null) {
                    throw ValidationException::withMessages(['causes' => 'Объединённую причину нельзя редактировать.']);
                }
                $duplicate = $lockedCauses->first(function (MedicalCauseDefinition $existing) use ($cause, $model): bool {
                    if ($model->exists && (int) $existing->getKey() === (int) $model->getKey()) {
                        return false;
                    }

                    $existingValues = [
                        MedicalCauseDirectory::normalizeIdentityValue((string) $existing->name),
                        ...array_map(
                            static fn (string $synonym): string => MedicalCauseDirectory::normalizeIdentityValue($synonym),
                            is_array($existing->synonyms) ? $existing->synonyms : [],
                        ),
                    ];

                    return array_intersect($existingValues, $cause['identities']) !== [];
                });
                if ($duplicate instanceof MedicalCauseDefinition) {
                    throw ValidationException::withMessages(['causes' => 'Такая причина или её синоним уже есть в справочнике.']);
                }
                $model->forceFill([
                    'organization_id' => $organizationId,
                    'name' => $cause['name'],
                    'group_name' => $cause['group_name'],
                    'synonyms' => $cause['synonyms'],
                    'tooltip' => $cause['tooltip'],
                    'moderation_status' => $cause['moderation_status'],
                    'is_active' => $cause['is_active'],
                    'sort_order' => $cause['sort_order'],
                ]);
                $model->save();
                $causeNames[] = $cause['name'];
            }
            MedicalCauseDefinition::query()
                ->where('organization_id', $organizationId)
                ->whereNull('merged_into_id')
                ->when($causeNames !== [], fn ($query) => $query->whereNotIn('name', $causeNames))
                ->update(['is_active' => false]);

            $this->validateCauseLinks($organizationId, $normalizedTests);
            $testKeys = [];
            $stage1bTestCount = 0;
            $causeLinkCount = 0;
            foreach ($normalizedTests as $test) {
                $definition = MedicalTestDefinition::query()->where('organization_id', $organizationId)->where('key', $test['key'])->lockForUpdate()->first() ?? new MedicalTestDefinition;
                $existingType = $definition->type()?->value;
                $definition->forceFill([
                    'organization_id' => $organizationId,
                    'key' => $test['key'],
                    'name' => $test['name'],
                    'section' => $test['section'],
                    'is_active' => $test['is_active'],
                    'sort_order' => $test['sort_order'],
                ]);
                if ($test['stage_configured']) {
                    $configuration = $test['catalog_config'];
                    if ($existingType === $test['test_type']) {
                        $configuration = $this->mergeConfiguration($definition->catalogConfiguration(), $configuration);
                    }
                    $definition->forceFill([
                        'test_type' => $test['test_type'],
                        'catalog_config' => $configuration,
                        'catalog_set' => $test['catalog_set'],
                        'default_in_session' => $test['default_in_session'],
                    ]);
                    $stage1bTestCount++;
                }
                $definition->save();
                $testKeys[] = $test['key'];
                $valueKeys = [];
                foreach ($test['values'] as $value) {
                    $model = MedicalTestValue::query()
                        ->where('organization_id', $organizationId)
                        ->where('medical_test_definition_id', $definition->getKey())
                        ->where('key', $value['key'])
                        ->lockForUpdate()
                        ->first() ?? new MedicalTestValue;
                    $model->forceFill([
                        'organization_id' => $organizationId,
                        'medical_test_definition_id' => $definition->getKey(),
                        'key' => $value['key'],
                        'label' => $value['label'],
                        'is_abnormal' => $value['is_abnormal'],
                        'is_active' => $value['is_active'],
                        'sort_order' => $value['sort_order'],
                    ]);
                    if ($test['stage_configured'] && $value['has_value_type']) {
                        $model->forceFill(['value_type' => $value['value_type']]);
                    }
                    $model->save();
                    $valueKeys[] = $value['key'];
                }
                MedicalTestValue::query()
                    ->where('organization_id', $organizationId)
                    ->where('medical_test_definition_id', $definition->getKey())
                    ->when($valueKeys !== [], fn ($query) => $query->whereNotIn('key', $valueKeys))
                    ->update(['is_active' => false]);

                if ($test['stage_configured']) {
                    $this->syncCauseLinks($organizationId, (int) $definition->getKey(), $test['cause_links']);
                    $causeLinkCount += count($test['cause_links']);
                }
            }
            MedicalTestDefinition::query()->where('organization_id', $organizationId)->when($testKeys !== [], fn ($query) => $query->whereNotIn('key', $testKeys))->update(['is_active' => false]);

            if ($normalizedProblemTags !== null) {
                $tagNames = [];
                foreach ($normalizedProblemTags as $tag) {
                    $model = MedicalProblemTag::query()
                        ->where('organization_id', $organizationId)
                        ->where('name', $tag['name'])
                        ->lockForUpdate()
                        ->first() ?? new MedicalProblemTag;
                    $model->forceFill([
                        'organization_id' => $organizationId,
                        'name' => $tag['name'],
                        'group_name' => $tag['group_name'],
                        'is_active' => $tag['is_active'],
                        'sort_order' => $tag['sort_order'],
                    ]);
                    $model->save();
                    $tagNames[] = $tag['name'];
                }
                $inactiveTags = MedicalProblemTag::query()
                    ->where('organization_id', $organizationId)
                    ->when($tagNames !== [], fn ($query) => $query->whereNotIn('name', $tagNames))
                    ->lockForUpdate()
                    ->get();
                foreach ($inactiveTags as $inactiveTag) {
                    $inactiveTag->forceFill(['is_active' => false])->save();
                }
            }

            if ($normalizedComplaints !== null) {
                $complaintIds = [];
                foreach ($normalizedComplaints as $complaint) {
                    $query = MedicalComplaintDefinition::query()->where('organization_id', $organizationId);
                    if ($complaint['id'] !== null) {
                        $query->whereKey($complaint['id']);
                    } else {
                        $query->where('name', $complaint['name']);
                    }
                    $model = $query->lockForUpdate()->first() ?? new MedicalComplaintDefinition;
                    $model->forceFill([
                        'organization_id' => $organizationId,
                        'name' => $complaint['name'],
                        'group_name' => $complaint['group_name'],
                        'synonyms' => $complaint['synonyms'],
                        'is_active' => $complaint['is_active'],
                        'sort_order' => $complaint['sort_order'],
                    ])->save();
                    $complaintIds[] = $model->getKey();
                }
                MedicalComplaintDefinition::query()
                    ->where('organization_id', $organizationId)
                    ->when($complaintIds !== [], fn ($query) => $query->whereNotIn('id', $complaintIds))
                    ->update(['is_active' => false]);
            }

            $this->audit->handle(
                organization: $organization,
                actor: $actor,
                action: 'medical.catalog.updated',
                targetType: MedicalTestDefinition::class,
                targetId: 'medical-clinical-catalog',
                metadata: [
                    'test_count' => count($normalizedTests),
                    'cause_count' => count($normalizedCauses),
                    'stage1b_test_count' => $stage1bTestCount,
                    'stage1b_cause_link_count' => $causeLinkCount,
                    'problem_tag_count' => $normalizedProblemTags === null ? null : count($normalizedProblemTags),
                    'complaint_count' => $normalizedComplaints === null ? null : count($normalizedComplaints),
                ],
            );
        });
    }

    /**
     * @param  array<int, mixed>  $tests
     * @return list<array<string, mixed>>
     */
    private function normalizeTests(array $tests): array
    {
        $normalized = [];
        $keys = [];
        foreach (array_values($tests) as $index => $raw) {
            if (! is_array($raw)) {
                throw ValidationException::withMessages(['tests' => 'Проверьте каталог тестов.']);
            }
            $key = trim((string) ($raw['key'] ?? ''));
            if (! Str::isUuid($key) && ! preg_match('/^[a-z0-9][a-z0-9._-]{1,119}$/', $key)) {
                $key = 'test_'.Str::lower(Str::random(12));
            }
            if (isset($keys[$key])) {
                throw ValidationException::withMessages(['tests' => 'Ключи тестов должны быть уникальны.']);
            }
            $name = trim((string) ($raw['name'] ?? ''));
            if ($name === '' || mb_strlen($name) > 255) {
                throw ValidationException::withMessages(['tests' => 'Укажите название теста.']);
            }

            $stage = $this->normalizeStageConfiguration($raw);
            $rawValues = $raw['values'] ?? [];
            if ($rawValues === null) {
                $rawValues = [];
            }
            if (! is_array($rawValues)) {
                throw ValidationException::withMessages(['tests' => 'Проверьте значения теста.']);
            }
            if ($rawValues === [] && $stage['configured']) {
                $rawValues = [
                    ['key' => 'normal', 'label' => 'Норма', 'value_type' => 'normal', 'is_abnormal' => false, 'is_active' => true, 'sort_order' => 0],
                    ['key' => 'violation', 'label' => 'Нарушение', 'value_type' => 'choice', 'is_abnormal' => true, 'is_active' => true, 'sort_order' => 1],
                ];
            }

            $values = [];
            $valueKeys = [];
            foreach (array_values($rawValues) as $valueIndex => $valueRaw) {
                if (! is_array($valueRaw)) {
                    throw ValidationException::withMessages(['tests' => 'Проверьте значения теста.']);
                }
                $valueKey = trim((string) ($valueRaw['key'] ?? ''));
                if (! Str::isUuid($valueKey) && ! preg_match('/^[a-z0-9][a-z0-9._-]{1,119}$/', $valueKey)) {
                    $valueKey = 'value_'.Str::lower(Str::random(12));
                }
                $label = trim((string) ($valueRaw['label'] ?? ''));
                if ($label === '' || isset($valueKeys[$valueKey])) {
                    throw ValidationException::withMessages(['tests' => 'Названия значений теста должны быть заполнены и уникальны.']);
                }
                $valueKeys[$valueKey] = true;
                $hasValueType = array_key_exists('value_type', $valueRaw);
                $values[] = [
                    'key' => $valueKey,
                    'label' => $label,
                    'value_type' => $hasValueType ? $this->normalizeValueType($valueRaw['value_type']) : null,
                    'has_value_type' => $hasValueType,
                    'is_abnormal' => $this->normalizeBoolean($valueRaw['is_abnormal'] ?? false, true),
                    'is_active' => $this->normalizeBoolean($valueRaw['is_active'] ?? true, true),
                    'sort_order' => $this->normalizeInteger($valueRaw['sort_order'] ?? $valueIndex, $valueIndex),
                ];
            }
            if ($values === []) {
                throw ValidationException::withMessages(['tests' => 'Добавьте хотя бы одно значение для каждого теста.']);
            }
            $keys[$key] = true;
            $normalized[] = [
                'key' => $key,
                'name' => $name,
                'section' => filled($raw['section'] ?? null) ? trim((string) $raw['section']) : null,
                'is_active' => $this->normalizeBoolean($raw['is_active'] ?? true, true),
                'sort_order' => $this->normalizeInteger($raw['sort_order'] ?? $index, $index),
                'values' => $values,
                'stage_configured' => $stage['configured'],
                'test_type' => $stage['type']?->value,
                'catalog_set' => $stage['catalog_set'],
                'default_in_session' => $stage['default_in_session'],
                'catalog_config' => $stage['configuration'],
                'cause_links' => $stage['cause_links'],
            ];
        }

        return $normalized;
    }

    /** @return array{configured:bool,type:?MedicalTestType,catalog_set:?string,default_in_session:bool,configuration:array<string,mixed>,cause_links:list<array{cause_id:int,scope_key:?string}>} */
    private function normalizeStageConfiguration(array $raw): array
    {
        $typeRaw = array_key_exists('test_type', $raw) ? $raw['test_type'] : ($raw['type'] ?? null);
        $type = null;
        if ($typeRaw instanceof MedicalTestType) {
            $type = $typeRaw;
        } elseif ($typeRaw !== null && ! is_string($typeRaw)) {
            throw ValidationException::withMessages(['tests' => 'Укажите корректный тип клинического теста.']);
        } elseif (is_string($typeRaw) && trim($typeRaw) !== '') {
            $type = MedicalTestType::tryFrom(strtoupper(trim($typeRaw)));
            if ($type === null) {
                throw ValidationException::withMessages(['tests' => 'Укажите корректный тип клинического теста.']);
            }
        }

        $setRaw = array_key_exists('catalog_set', $raw) ? $raw['catalog_set'] : ($raw['set'] ?? null);
        $setProvided = $setRaw !== null && is_string($setRaw) && trim($setRaw) !== '';
        if ($setRaw !== null && ! is_string($setRaw)) {
            throw ValidationException::withMessages(['tests' => 'Укажите набор теста.']);
        }
        $catalogSet = $setProvided ? $this->normalizeCatalogSet($setRaw) : null;

        $configuration = null;
        foreach (['catalog_config', 'configuration'] as $field) {
            if (! array_key_exists($field, $raw)) {
                continue;
            }
            if ($raw[$field] === null) {
                $configuration = [];

                continue;
            }
            if (! is_array($raw[$field])) {
                throw ValidationException::withMessages(['tests' => 'Настройка клинического теста должна быть структурированной.']);
            }
            $configuration = $raw[$field];
        }
        $configuration ??= [];
        foreach (['rows', 'subtests', 'rules', 'help_text', 'safety_marker_text', 'default_subtests'] as $field) {
            if (array_key_exists($field, $raw)) {
                $configuration[$field] = $raw[$field];
            }
        }

        $defaultRaw = array_key_exists('default_in_session', $raw) ? $raw['default_in_session'] : ($raw['is_default'] ?? false);
        $defaultInSession = $this->normalizeBoolean($defaultRaw, false);
        $directCauseIds = $this->normalizeCauseIds($raw['cause_ids'] ?? ($raw['allowed_cause_ids'] ?? []));
        $directCauseLinks = $this->normalizeCauseLinks($raw['cause_links'] ?? []);
        $configurationMeaningful = $this->configurationIsMeaningful($configuration);
        $configured = $type !== null
            || $setProvided
            || $configurationMeaningful
            || $defaultInSession
            || $directCauseIds !== []
            || $directCauseLinks !== [];

        if (! $configured) {
            return [
                'configured' => false,
                'type' => null,
                'catalog_set' => null,
                'default_in_session' => false,
                'configuration' => [],
                'cause_links' => [],
            ];
        }
        if ($type === null) {
            throw ValidationException::withMessages(['tests' => 'Для настройки Stage 1B укажите тип клинического теста.']);
        }
        $catalogSet ??= 'main';
        if ($defaultInSession && $catalogSet !== 'main') {
            throw ValidationException::withMessages(['tests' => 'В набор по умолчанию можно добавлять только основной тест.']);
        }

        $normalizedConfiguration = $this->normalizeCatalogConfiguration($configuration, $type);
        $causeLinks = $normalizedConfiguration['cause_links'];
        foreach ($directCauseIds as $causeId) {
            $causeLinks[] = ['cause_id' => $causeId, 'scope_key' => null];
        }
        foreach ($directCauseLinks as $link) {
            $causeLinks[] = $link;
        }

        $uniqueLinks = [];
        foreach ($causeLinks as $link) {
            $key = ($link['scope_key'] ?? 'root').':'.$link['cause_id'];
            $uniqueLinks[$key] = $link;
        }

        return [
            'configured' => true,
            'type' => $type,
            'catalog_set' => $catalogSet,
            'default_in_session' => $defaultInSession,
            'configuration' => $normalizedConfiguration['configuration'],
            'cause_links' => array_values($uniqueLinks),
        ];
    }

    private function configurationIsMeaningful(array $configuration): bool
    {
        foreach (['rows', 'subtests', 'default_subtests'] as $field) {
            if (is_array($configuration[$field] ?? null) && $configuration[$field] !== []) {
                return true;
            }
        }
        foreach (['help_text', 'safety_marker_text'] as $field) {
            if (is_string($configuration[$field] ?? null) && trim($configuration[$field]) !== '') {
                return true;
            }
        }
        if (is_array($configuration['rules'] ?? null)) {
            foreach ($configuration['rules'] as $value) {
                if ($value !== null && $value !== false && $value !== '' && $value !== []) {
                    return true;
                }
            }
        }

        return array_key_exists('version', $configuration) && $configuration['version'] !== null;
    }

    /** @return array{configuration:array<string,mixed>,cause_links:list<array{cause_id:int,scope_key:?string}>} */
    private function normalizeCatalogConfiguration(array $configuration, MedicalTestType $type): array
    {
        $version = $configuration['version'] ?? 1;
        if (! is_int($version) && ! (is_string($version) && ctype_digit($version))) {
            throw ValidationException::withMessages(['tests' => 'Версия настройки теста должна быть целым числом.']);
        }
        $version = (int) $version;
        if ($version < 1) {
            throw ValidationException::withMessages(['tests' => 'Версия настройки теста должна быть положительной.']);
        }

        $rawRows = $configuration['rows'] ?? [];
        if ($rawRows === null) {
            $rawRows = [];
        }
        if (! is_array($rawRows)) {
            throw ValidationException::withMessages(['tests' => 'Строки теста должны быть списком.']);
        }
        [$rows, $rowLinks] = $this->normalizeCatalogRows($rawRows, 'row');

        $rawFeelings = $configuration['feelings'] ?? [];
        if ($rawFeelings === null) {
            $rawFeelings = [];
        }
        if (! is_array($rawFeelings)) {
            throw ValidationException::withMessages(['tests' => 'Чувства теста должны быть списком.']);
        }
        [$feelings, $feelingLinks] = $this->normalizeCatalogRows($rawFeelings, 'feeling');

        $rawSubtests = $configuration['subtests'] ?? [];
        if ($rawSubtests === null) {
            $rawSubtests = [];
        }
        if (! is_array($rawSubtests)) {
            throw ValidationException::withMessages(['tests' => 'Подтесты должны быть списком.']);
        }
        [$subtests, $subtestLinks] = $this->normalizeCatalogSubtests($rawSubtests, $type);
        $subtestKeys = array_values(array_filter(array_map(static fn (mixed $subtest): string => is_array($subtest) ? (string) ($subtest['key'] ?? '') : '', $subtests)));

        $defaultSubtests = $configuration['default_subtests'] ?? null;
        if ($defaultSubtests !== null) {
            if (! is_array($defaultSubtests)) {
                throw ValidationException::withMessages(['tests' => 'Подтесты по умолчанию должны быть списком.']);
            }
            $defaultSubtests = array_values(array_filter(array_map(static fn (mixed $subtest): string => trim((string) $subtest), $defaultSubtests), static fn (string $subtest): bool => $subtest !== ''));
            if (array_diff($defaultSubtests, $subtestKeys) !== []) {
                throw ValidationException::withMessages(['tests' => 'Подтест по умолчанию должен существовать в настройке теста.']);
            }
        }

        $rules = $configuration['rules'] ?? [];
        if ($rules === null) {
            $rules = [];
        }
        if (! is_array($rules)) {
            throw ValidationException::withMessages(['tests' => 'Правила нарушения должны быть структурированными.']);
        }
        $rules = $this->normalizeRules($rules);
        $normalized = $configuration;
        $normalized['version'] = $version;
        $normalized['rows'] = $rows;
        $normalized['feelings'] = $feelings;
        $normalized['subtests'] = $subtests;
        $normalized['rules'] = $rules;
        if ($defaultSubtests !== null) {
            $normalized['default_subtests'] = $defaultSubtests;
        }
        foreach (['help_text', 'safety_marker_text'] as $field) {
            if (array_key_exists($field, $normalized) && $normalized[$field] !== null) {
                if (! is_string($normalized[$field]) || mb_strlen($normalized[$field]) > 2000) {
                    throw ValidationException::withMessages(['tests' => 'Текст настройки теста слишком длинный или имеет неверный формат.']);
                }
                $normalized[$field] = trim($normalized[$field]);
            }
        }
        unset($normalized['cause_ids'], $normalized['allowed_cause_ids']);

        return [
            'configuration' => $this->normalizeConfigValue($normalized),
            'cause_links' => [...$rowLinks, ...$feelingLinks, ...$subtestLinks, ...$this->linksForCauseIds($this->normalizeCauseIds($configuration['cause_ids'] ?? []), null)],
        ];
    }

    /** @return array{0:list<array<string,mixed>>,1:list<array{cause_id:int,scope_key:?string}>} */
    private function normalizeCatalogRows(array $rows, string $prefix): array
    {
        $normalized = [];
        $links = [];
        $keys = [];
        foreach (array_values($rows) as $index => $raw) {
            if (! is_array($raw)) {
                throw ValidationException::withMessages(['tests' => 'Строки теста должны быть структурированными.']);
            }
            $key = trim((string) ($raw['key'] ?? ''));
            if ($key === '') {
                $key = $prefix.'_'.($index + 1);
            }
            if (! preg_match('/^[A-Za-z0-9._-]{1,160}$/', $key) || isset($keys[$key])) {
                throw ValidationException::withMessages(['tests' => 'Обозначения строк теста должны быть уникальными.']);
            }
            $label = trim((string) ($raw['label'] ?? $raw['name'] ?? ''));
            if ($label === '' || mb_strlen($label) > 255) {
                throw ValidationException::withMessages(['tests' => 'Укажите название каждой строки теста.']);
            }
            $keys[$key] = true;
            $row = [];
            foreach ($raw as $field => $value) {
                if (in_array((string) $field, ['cause_ids', 'allowed_cause_ids'], true)) {
                    continue;
                }
                $row[(string) $field] = $this->normalizeConfigValue($value);
            }
            $row['key'] = $key;
            $row['label'] = $label;
            $row['sort_order'] = $this->normalizeInteger($raw['sort_order'] ?? $index, $index);
            $normalized[] = $row;
            $causeIds = $this->normalizeCauseIds($raw['cause_ids'] ?? ($raw['allowed_cause_ids'] ?? []));
            $links = [...$links, ...$this->linksForCauseIds($causeIds, $key)];
        }

        return [$normalized, $links];
    }

    /** @return array{0:list<array<string,mixed>>,1:list<array{cause_id:int,scope_key:?string}>} */
    private function normalizeCatalogSubtests(array $subtests, MedicalTestType $parentType): array
    {
        $normalized = [];
        $links = [];
        $keys = [];
        foreach (array_values($subtests) as $index => $raw) {
            if (! is_array($raw)) {
                throw ValidationException::withMessages(['tests' => 'Подтесты должны быть структурированными.']);
            }
            $key = trim((string) ($raw['key'] ?? ''));
            if ($key === '') {
                $key = 'subtest_'.($index + 1);
            }
            if (! preg_match('/^[A-Za-z0-9._-]{1,160}$/', $key) || isset($keys[$key])) {
                throw ValidationException::withMessages(['tests' => 'Обозначения подтестов должны быть уникальными.']);
            }
            $label = trim((string) ($raw['label'] ?? $raw['name'] ?? ''));
            if ($label === '' || mb_strlen($label) > 255) {
                throw ValidationException::withMessages(['tests' => 'Укажите название каждого подтеста.']);
            }
            if (array_key_exists('type', $raw) && $raw['type'] !== null) {
                if ($raw['type'] instanceof MedicalTestType) {
                    $raw['type'] = $raw['type']->value;
                } elseif (! is_string($raw['type']) || trim($raw['type']) === '' || MedicalTestType::tryFrom(strtoupper(trim($raw['type']))) === null) {
                    throw ValidationException::withMessages(['tests' => 'Укажите корректный тип подтеста.']);
                }
            }
            $keys[$key] = true;
            $subtest = [];
            foreach ($raw as $field => $value) {
                if (in_array((string) $field, ['cause_ids', 'allowed_cause_ids', 'rows'], true)) {
                    continue;
                }
                $subtest[(string) $field] = $this->normalizeConfigValue($value);
            }
            $subtest['key'] = $key;
            $subtest['label'] = $label;
            $subtest['sort_order'] = $this->normalizeInteger($raw['sort_order'] ?? $index, $index);
            $rawRows = $raw['rows'] ?? [];
            if ($rawRows === null) {
                $rawRows = [];
            }
            if (! is_array($rawRows)) {
                throw ValidationException::withMessages(['tests' => 'Строки подтеста должны быть списком.']);
            }
            [$subtest['rows'], $rowLinks] = $this->normalizeCatalogRows($rawRows, $key.'_row');
            if (array_key_exists('feelings', $raw)) {
                if ($raw['feelings'] !== null && ! is_array($raw['feelings'])) {
                    throw ValidationException::withMessages(['tests' => 'Чувства подтеста должны быть списком.']);
                }
                [$subtest['feelings'], $feelingLinks] = $this->normalizeCatalogRows($raw['feelings'] ?? [], $key.'_feeling');
                $rowLinks = [...$rowLinks, ...$feelingLinks];
            }
            if (array_key_exists('provoking_points', $raw)) {
                if ($raw['provoking_points'] !== null && ! is_array($raw['provoking_points'])) {
                    throw ValidationException::withMessages(['tests' => 'Провоцирующие точки должны быть списком.']);
                }
                [$subtest['provoking_points'], $provokingLinks] = $this->normalizeCatalogRows($raw['provoking_points'] ?? [], $key.'_point');
                $rowLinks = [...$rowLinks, ...$provokingLinks];
            }
            $normalized[] = $subtest;
            $causeIds = $this->normalizeCauseIds($raw['cause_ids'] ?? ($raw['allowed_cause_ids'] ?? []));
            $links = [...$links, ...$this->linksForCauseIds($causeIds, $key), ...$rowLinks];
        }

        return [$normalized, $links];
    }

    /** @return array<string,mixed> */
    private function normalizeRules(array $rules): array
    {
        if (array_key_exists('index_violation', $rules) && $rules['index_violation'] !== null) {
            if (! is_array($rules['index_violation'])) {
                throw ValidationException::withMessages(['tests' => 'Индексы нарушения должны быть списком.']);
            }
            $rules['index_violation'] = array_values(array_unique(array_map(function (mixed $index): int {
                if (! is_numeric($index) || (int) $index < 0 || (int) $index > 3 || (float) $index !== (float) (int) $index) {
                    throw ValidationException::withMessages(['tests' => 'Индекс нарушения должен быть от 0 до 3.']);
                }

                return (int) $index;
            }, $rules['index_violation'])));
        }
        if (array_key_exists('allowed_sequences', $rules) && $rules['allowed_sequences'] !== null) {
            if (! is_array($rules['allowed_sequences'])) {
                throw ValidationException::withMessages(['tests' => 'Допустимые последовательности должны быть списком.']);
            }
            $rules['allowed_sequences'] = array_values(array_map(static function (mixed $sequence): array {
                if (is_array($sequence) && array_key_exists('values', $sequence)) {
                    $sequence = $sequence['values'];
                }
                if (! is_array($sequence)) {
                    throw ValidationException::withMessages(['tests' => 'Каждая последовательность должна быть списком вариантов.']);
                }

                return array_values(array_map(static fn (mixed $value): string => trim((string) $value, " \t\n\r\0\x0B"), $sequence));
            }, $rules['allowed_sequences']));
        }

        return $this->normalizeConfigValue($rules);
    }

    private function normalizeConfigValue(mixed $value, int $depth = 0): mixed
    {
        if ($depth > 8) {
            throw ValidationException::withMessages(['tests' => 'Настройка теста слишком глубоко вложена.']);
        }
        if (is_array($value)) {
            $normalized = [];
            foreach ($value as $key => $child) {
                if (! is_int($key) && ! is_string($key)) {
                    throw ValidationException::withMessages(['tests' => 'Настройка теста содержит некорректный ключ.']);
                }
                $normalized[$key] = $this->normalizeConfigValue($child, $depth + 1);
            }

            return $normalized;
        }
        if (is_string($value) || is_int($value) || is_float($value) || is_bool($value) || $value === null) {
            return $value;
        }

        throw ValidationException::withMessages(['tests' => 'Настройка теста содержит неподдерживаемое значение.']);
    }

    private function normalizeCatalogSet(mixed $value): string
    {
        if (! is_string($value)) {
            throw ValidationException::withMessages(['tests' => 'Укажите набор теста.']);
        }
        $value = match (mb_strtolower(trim($value))) {
            'основные', 'основные тесты' => 'main',
            'специализированные', 'специализированные тесты' => 'specialized',
            default => trim($value),
        };
        if (! in_array($value, ['main', 'specialized'], true)) {
            throw ValidationException::withMessages(['tests' => 'Укажите корректный набор теста.']);
        }

        return $value;
    }

    private function normalizeValueType(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (! is_string($value) || ! in_array($value, ['normal', 'choice', 'boolean', 'integer', 'decimal', 'text'], true)) {
            throw ValidationException::withMessages(['tests' => 'Укажите корректный тип значения теста.']);
        }

        return $value;
    }

    private function normalizeBoolean(mixed $value, bool $default): bool
    {
        if ($value === null) {
            return $default;
        }
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value) && in_array($value, [0, 1], true)) {
            return $value === 1;
        }
        if (is_string($value)) {
            return match (mb_strtolower(trim($value))) {
                '1', 'true', 'yes', 'да' => true,
                '0', 'false', 'no', 'нет' => false,
                default => throw ValidationException::withMessages(['tests' => 'Поле каталога должно быть логическим.']),
            };
        }

        throw ValidationException::withMessages(['tests' => 'Поле каталога должно быть логическим.']);
    }

    private function normalizeInteger(mixed $value, int $default): int
    {
        if ($value === null || $value === '') {
            return $default;
        }
        if (! is_int($value) && ! (is_string($value) && preg_match('/^-?\d+$/', $value) === 1)) {
            throw ValidationException::withMessages(['tests' => 'Порядок элементов каталога должен быть целым числом.']);
        }

        return (int) $value;
    }

    /** @return list<int> */
    private function normalizeCauseIds(mixed $value): array
    {
        if ($value === null || $value === '') {
            return [];
        }
        if (! is_array($value)) {
            $value = [$value];
        }
        $ids = [];
        foreach ($value as $id) {
            if (! is_int($id) && ! (is_string($id) && ctype_digit($id))) {
                throw ValidationException::withMessages(['causes' => 'Причины должны быть выбраны из справочника.']);
            }
            $id = (int) $id;
            if ($id < 1) {
                throw ValidationException::withMessages(['causes' => 'Причины должны быть выбраны из справочника.']);
            }
            $ids[$id] = $id;
        }

        return array_values($ids);
    }

    /** @return list<array{cause_id:int,scope_key:?string}> */
    private function normalizeCauseLinks(mixed $value): array
    {
        if ($value === null || $value === []) {
            return [];
        }
        if (! is_array($value)) {
            throw ValidationException::withMessages(['causes' => 'Связи причин должны быть списком.']);
        }
        $links = [];
        foreach (array_values($value) as $raw) {
            if (! is_array($raw)) {
                throw ValidationException::withMessages(['causes' => 'Связи причин должны быть структурированными.']);
            }
            $scope = $raw['scope_key'] ?? null;
            if ($scope !== null && (! is_string($scope) || mb_strlen(trim($scope)) > 160)) {
                throw ValidationException::withMessages(['causes' => 'Обозначение строки причины слишком длинное.']);
            }
            foreach ($this->normalizeCauseIds($raw['cause_ids'] ?? ($raw['cause_id'] ?? [])) as $causeId) {
                $links[] = ['cause_id' => $causeId, 'scope_key' => $scope === null || trim($scope) === '' ? null : trim($scope)];
            }
        }

        return $links;
    }

    /** @param list<int> $causeIds
     * @return list<array{cause_id:int,scope_key:?string}>
     */
    private function linksForCauseIds(array $causeIds, ?string $scopeKey): array
    {
        return array_map(static fn (int $causeId): array => ['cause_id' => $causeId, 'scope_key' => $scopeKey], $causeIds);
    }

    /** @param list<array<string,mixed>> $tests */
    private function validateCauseLinks(int $organizationId, array $tests): void
    {
        $causeIds = [];
        foreach ($tests as $test) {
            foreach ($test['cause_links'] ?? [] as $link) {
                $causeIds[(int) $link['cause_id']] = true;
            }
        }
        if ($causeIds === []) {
            return;
        }

        $validCount = MedicalCauseDefinition::query()
            ->where('organization_id', $organizationId)
            ->whereIn('id', array_keys($causeIds))
            ->count();
        if ($validCount !== count($causeIds)) {
            throw ValidationException::withMessages(['causes' => 'Выберите причины только из справочника этой организации.']);
        }
    }

    /** @param list<array{cause_id:int,scope_key:?string}> $links */
    private function syncCauseLinks(int $organizationId, int $testId, array $links): void
    {
        MedicalTestCauseLink::query()
            ->where('organization_id', $organizationId)
            ->where('medical_test_definition_id', $testId)
            ->update(['is_active' => false]);

        foreach ($links as $link) {
            $query = MedicalTestCauseLink::query()
                ->where('organization_id', $organizationId)
                ->where('medical_test_definition_id', $testId)
                ->where('medical_cause_definition_id', $link['cause_id']);
            if ($link['scope_key'] === null) {
                $query->whereNull('scope_key');
            } else {
                $query->where('scope_key', $link['scope_key']);
            }
            $model = $query->lockForUpdate()->first() ?? new MedicalTestCauseLink;
            $model->forceFill([
                'organization_id' => $organizationId,
                'medical_test_definition_id' => $testId,
                'medical_cause_definition_id' => $link['cause_id'],
                'scope_key' => $link['scope_key'],
                'is_active' => true,
            ])->save();
        }
    }

    /** @return array<string,mixed> */
    private function mergeConfiguration(array $existing, array $incoming): array
    {
        foreach ($incoming as $key => $value) {
            if (in_array($key, ['rows', 'subtests'], true) && is_array($value) && is_array($existing[$key] ?? null)) {
                $existingByKey = [];
                foreach ($existing[$key] as $existingItem) {
                    if (is_array($existingItem) && filled($existingItem['key'] ?? null)) {
                        $existingByKey[(string) $existingItem['key']] = $existingItem;
                    }
                }
                $value = array_map(function (mixed $item) use ($existingByKey): mixed {
                    if (! is_array($item) || ! filled($item['key'] ?? null) || ! isset($existingByKey[(string) $item['key']])) {
                        return $item;
                    }

                    return $this->mergeConfiguration($existingByKey[(string) $item['key']], $item);
                }, $value);
                $existing[$key] = $value;

                continue;
            }
            if (is_array($value) && is_array($existing[$key] ?? null)) {
                $existing[$key] = $this->mergeConfiguration($existing[$key], $value);

                continue;
            }
            $existing[$key] = $value;
        }

        return $existing;
    }

    /**
     * @param  array<int, mixed>  $causes
     * @return list<array{id:int|null,name:string,group_name:?string,synonyms:list<string>,tooltip:?string,moderation_status:string,is_active:bool,sort_order:int,identities:list<string>}>
     */
    private function normalizeCauses(array $causes): array
    {
        $normalized = [];
        $names = [];
        foreach (array_values($causes) as $index => $raw) {
            if (! is_array($raw)) {
                throw ValidationException::withMessages(['causes' => 'Проверьте справочник причин.']);
            }
            $name = trim((string) ($raw['name'] ?? ''));
            if ($name === '' || mb_strlen($name) > 255) {
                throw ValidationException::withMessages(['causes' => 'Укажите название причины длиной до 255 символов.']);
            }
            $id = is_numeric($raw['id'] ?? null) && (int) $raw['id'] > 0 ? (int) $raw['id'] : null;
            $synonyms = MedicalCauseDirectory::normalizeSynonyms($raw['synonyms'] ?? []);
            $identities = array_values(array_unique(array_filter([
                MedicalCauseDirectory::normalizeIdentityValue($name),
                ...array_map(static fn (string $synonym): string => MedicalCauseDirectory::normalizeIdentityValue($synonym), $synonyms),
            ])));
            if ($identities === [] || array_intersect_key(array_flip($identities), $names) !== []) {
                throw ValidationException::withMessages(['causes' => 'Названия причин и синонимы должны быть уникальны.']);
            }
            foreach ($identities as $identity) {
                $names[$identity] = true;
            }
            $moderationStatus = trim((string) ($raw['moderation_status'] ?? MedicalCauseDefinition::MODERATION_ACTIVE));
            if ($moderationStatus === 'pending') {
                $moderationStatus = MedicalCauseDefinition::MODERATION_PENDING;
            }
            if (! in_array($moderationStatus, [
                MedicalCauseDefinition::MODERATION_ACTIVE,
                MedicalCauseDefinition::MODERATION_PENDING,
                MedicalCauseDefinition::MODERATION_HIDDEN,
            ], true)) {
                throw ValidationException::withMessages(['causes' => 'Укажите корректный статус причины.']);
            }
            $isActive = filter_var($raw['is_active'] ?? true, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? false;
            if ($moderationStatus === MedicalCauseDefinition::MODERATION_HIDDEN) {
                $isActive = false;
            }
            $tooltip = $raw['tooltip'] ?? null;
            if ($tooltip !== null && ! is_string($tooltip)) {
                throw ValidationException::withMessages(['causes' => 'Подсказка причины должна быть текстом.']);
            }
            $tooltip = trim((string) ($tooltip ?? ''));
            if (mb_strlen($tooltip) > 4000) {
                throw ValidationException::withMessages(['causes' => 'Подсказка причины слишком длинная.']);
            }
            $normalized[] = [
                'id' => $id,
                'name' => $name,
                'group_name' => filled($raw['group_name'] ?? null) ? trim((string) $raw['group_name']) : null,
                'synonyms' => $synonyms,
                'tooltip' => $tooltip === '' ? null : $tooltip,
                'moderation_status' => $moderationStatus,
                'is_active' => $isActive,
                'sort_order' => (int) ($raw['sort_order'] ?? $index),
                'identities' => $identities,
            ];
        }

        return $normalized;
    }

    /**
     * @param  array<int, mixed>  $tags
     * @return list<array{name:string,group_name:?string,is_active:bool,sort_order:int}>
     */
    private function normalizeProblemTags(array $tags): array
    {
        $normalized = [];
        $names = [];
        foreach (array_values($tags) as $index => $raw) {
            if (! is_array($raw)) {
                throw ValidationException::withMessages(['problem_tags' => 'Проверьте категории проблем.']);
            }
            $name = trim((string) ($raw['name'] ?? ''));
            if ($name === '' || isset($names[$name])) {
                throw ValidationException::withMessages(['problem_tags' => 'Названия категорий проблем должны быть заполнены и уникальны.']);
            }
            if (mb_strlen($name) > 255) {
                throw ValidationException::withMessages(['problem_tags' => 'Название категории слишком длинное.']);
            }
            $names[$name] = true;
            $normalized[] = [
                'name' => $name,
                'group_name' => filled($raw['group_name'] ?? null) ? trim((string) $raw['group_name']) : null,
                'is_active' => (bool) ($raw['is_active'] ?? true),
                'sort_order' => (int) ($raw['sort_order'] ?? $index),
            ];
        }

        return $normalized;
    }

    /**
     * @param  array<int, mixed>  $complaints
     * @return list<array{id:int|null,name:string,group_name:?string,synonyms:list<string>,is_active:bool,sort_order:int}>
     */
    private function normalizeComplaints(array $complaints): array
    {
        $normalized = [];
        $names = [];
        foreach (array_values($complaints) as $index => $raw) {
            if (! is_array($raw)) {
                throw ValidationException::withMessages(['complaints' => 'Проверьте справочник жалоб.']);
            }
            $name = trim((string) ($raw['name'] ?? ''));
            if ($name === '' || mb_strlen($name) > 255 || isset($names[$name])) {
                throw ValidationException::withMessages(['complaints' => 'Названия жалоб должны быть заполнены и уникальны.']);
            }
            $names[$name] = true;
            $synonyms = preg_split('/\R/u', (string) ($raw['synonyms'] ?? '')) ?: [];
            $synonyms = array_values(array_unique(array_filter(array_map(static fn (string $value): string => trim($value), $synonyms), static fn (string $value): bool => $value !== '')));
            $normalized[] = [
                'id' => isset($raw['id']) && is_numeric($raw['id']) ? (int) $raw['id'] : null,
                'name' => $name,
                'group_name' => filled($raw['group_name'] ?? null) ? trim((string) $raw['group_name']) : null,
                'synonyms' => $synonyms,
                'is_active' => (bool) ($raw['is_active'] ?? true),
                'sort_order' => (int) ($raw['sort_order'] ?? $index),
            ];
        }

        return $normalized;
    }
}
