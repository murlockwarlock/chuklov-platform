<?php

namespace App\Modules\Sessions\Application;

use App\Models\User;
use App\Modules\MedicalProfiles\Domain\Models\MedicalProblemTag;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use App\Modules\Security\Application\RecordAuditEvent;
use App\Modules\Sessions\Domain\Models\MedicalCauseDefinition;
use App\Modules\Sessions\Domain\Models\MedicalTestDefinition;
use App\Modules\Sessions\Domain\Models\MedicalTestValue;
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
     */
    public function handle(User $actor, array $tests, array $causes, ?array $problemTags = null): void
    {
        $organization = $this->context->organization();
        if (! $this->authorizer->allows($actor, $organization, OrganizationPermission::ManageSettings)) {
            throw ValidationException::withMessages(['catalog' => 'Недостаточно прав для изменения справочника.']);
        }

        $normalizedTests = $this->normalizeTests($tests);
        $normalizedCauses = $this->normalizeCauses($causes);
        $normalizedProblemTags = $problemTags === null ? null : $this->normalizeProblemTags($problemTags);
        $organizationId = (int) $organization->getKey();

        DB::transaction(function () use ($organizationId, $normalizedTests, $normalizedCauses, $normalizedProblemTags, $organization, $actor): void {
            $testKeys = [];
            foreach ($normalizedTests as $test) {
                $definition = MedicalTestDefinition::query()->where('organization_id', $organizationId)->where('key', $test['key'])->lockForUpdate()->first() ?? new MedicalTestDefinition;
                $definition->forceFill([
                    'organization_id' => $organizationId,
                    'key' => $test['key'],
                    'name' => $test['name'],
                    'section' => $test['section'],
                    'is_active' => $test['is_active'],
                    'sort_order' => $test['sort_order'],
                ]);
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
                    $model->save();
                    $valueKeys[] = $value['key'];
                }
                MedicalTestValue::query()
                    ->where('organization_id', $organizationId)
                    ->where('medical_test_definition_id', $definition->getKey())
                    ->when($valueKeys !== [], fn ($query) => $query->whereNotIn('key', $valueKeys))
                    ->update(['is_active' => false]);
            }
            MedicalTestDefinition::query()->where('organization_id', $organizationId)->when($testKeys !== [], fn ($query) => $query->whereNotIn('key', $testKeys))->update(['is_active' => false]);

            $causeNames = [];
            foreach ($normalizedCauses as $cause) {
                $model = MedicalCauseDefinition::query()->where('organization_id', $organizationId)->where('name', $cause['name'])->lockForUpdate()->first() ?? new MedicalCauseDefinition;
                $model->forceFill([
                    'organization_id' => $organizationId,
                    'name' => $cause['name'],
                    'group_name' => $cause['group_name'],
                    'is_active' => $cause['is_active'],
                    'sort_order' => $cause['sort_order'],
                ]);
                $model->save();
                $causeNames[] = $cause['name'];
            }
            MedicalCauseDefinition::query()->where('organization_id', $organizationId)->when($causeNames !== [], fn ($query) => $query->whereNotIn('name', $causeNames))->update(['is_active' => false]);

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

            $this->audit->handle(
                organization: $organization,
                actor: $actor,
                action: 'medical.catalog.updated',
                targetType: MedicalTestDefinition::class,
                targetId: 'medical-clinical-catalog',
                metadata: [
                    'test_count' => count($normalizedTests),
                    'cause_count' => count($normalizedCauses),
                    'problem_tag_count' => $normalizedProblemTags === null ? null : count($normalizedProblemTags),
                ],
            );
        });
    }

    /**
     * @param  array<int, mixed>  $tests
     * @return list<array{key:string,name:string,section:?string,is_active:bool,sort_order:int,values:list<array{key:string,label:string,is_abnormal:bool,is_active:bool,sort_order:int}>}>
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
            $values = [];
            $valueKeys = [];
            foreach (array_values(is_array($raw['values'] ?? null) ? $raw['values'] : []) as $valueIndex => $valueRaw) {
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
                $values[] = ['key' => $valueKey, 'label' => $label, 'is_abnormal' => (bool) ($valueRaw['is_abnormal'] ?? false), 'is_active' => (bool) ($valueRaw['is_active'] ?? true), 'sort_order' => (int) ($valueRaw['sort_order'] ?? $valueIndex)];
            }
            if ($values === []) {
                throw ValidationException::withMessages(['tests' => 'Добавьте хотя бы одно значение для каждого теста.']);
            }
            $keys[$key] = true;
            $normalized[] = ['key' => $key, 'name' => $name, 'section' => filled($raw['section'] ?? null) ? trim((string) $raw['section']) : null, 'is_active' => (bool) ($raw['is_active'] ?? true), 'sort_order' => (int) ($raw['sort_order'] ?? $index), 'values' => $values];
        }

        return $normalized;
    }

    /**
     * @param  array<int, mixed>  $causes
     * @return list<array{name:string,group_name:?string,is_active:bool,sort_order:int}>
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
            if ($name === '' || isset($names[$name])) {
                throw ValidationException::withMessages(['causes' => 'Названия причин должны быть заполнены и уникальны.']);
            }
            $names[$name] = true;
            $normalized[] = ['name' => $name, 'group_name' => filled($raw['group_name'] ?? null) ? trim((string) $raw['group_name']) : null, 'is_active' => (bool) ($raw['is_active'] ?? true), 'sort_order' => (int) ($raw['sort_order'] ?? $index)];
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
}
