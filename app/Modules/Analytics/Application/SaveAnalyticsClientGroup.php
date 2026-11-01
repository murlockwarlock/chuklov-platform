<?php

namespace App\Modules\Analytics\Application;

use App\Models\User;
use App\Modules\Analytics\Application\Data\ClientCohortFilters;
use App\Modules\Analytics\Domain\Models\AnalyticsClientGroup;
use App\Modules\Analytics\Domain\Models\AnalyticsClientGroupVersion;
use App\Modules\Feedback\Domain\Models\FeedbackTheme;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\MedicalProfiles\Domain\Models\MedicalProblemTag;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Security\Application\RecordAuditEvent;
use App\Modules\Services\Domain\Models\Service;
use App\Modules\Specialists\Domain\Models\Specialist;
use App\Modules\Tracker\Domain\Models\TrackerGoalTag;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final readonly class SaveAnalyticsClientGroup
{
    public function __construct(
        private OrganizationContext $context,
        private OrganizationAuthorizer $authorizer,
        private RecordAuditEvent $audit,
    ) {}

    /** @param array<string, mixed> $data
     * @param  list<int>  $clientIds
     */
    public function handle(User $actor, array $data, array $clientIds = []): AnalyticsClientGroup
    {
        $organization = $this->context->organization();
        $this->authorizer->authorize($actor, $organization, OrganizationPermission::ManageSettings);
        [$name, $type, $ruleDefinition, $normalizedClientIds] = $this->prepare($data, $clientIds);
        $this->assertReferencesBelongToOrganization($organization, $type, $ruleDefinition, $normalizedClientIds);

        try {
            return DB::transaction(fn (): AnalyticsClientGroup => $this->persist(
                $actor,
                $organization,
                $data,
                $name,
                $type,
                $ruleDefinition,
                $normalizedClientIds,
            ));
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['name' => 'В этой организации уже есть группа с таким названием.']);
        }
    }

    /** @param list<array{data: array<string, mixed>, client_ids: list<int>}> $groups */
    public function handleMany(User $actor, array $groups): void
    {
        $organization = $this->context->organization();
        $this->authorizer->authorize($actor, $organization, OrganizationPermission::ManageSettings);
        $prepared = [];
        $names = [];
        foreach ($groups as $entry) {
            if (! is_array($entry) || ! is_array($entry['data'] ?? null) || ! is_array($entry['client_ids'] ?? null)) {
                throw ValidationException::withMessages(['groups' => 'Проверьте данные всех групп перед сохранением.']);
            }
            [$name, $type, $ruleDefinition, $normalizedClientIds] = $this->prepare($entry['data'], $entry['client_ids']);
            $this->assertReferencesBelongToOrganization($organization, $type, $ruleDefinition, $normalizedClientIds);
            $nameKey = mb_strtolower($name);
            if (isset($names[$nameKey])) {
                throw ValidationException::withMessages(['name' => 'В этой организации уже есть группа с таким названием.']);
            }
            $id = filter_var($entry['data']['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            $duplicate = $this->hasDuplicateName(
                $organization,
                $name,
                $id !== false ? (int) $id : null,
            );
            if ($duplicate) {
                throw ValidationException::withMessages(['name' => 'В этой организации уже есть группа с таким названием.']);
            }
            $names[$nameKey] = true;
            $prepared[] = [$entry['data'], $name, $type, $ruleDefinition, $normalizedClientIds];
        }

        try {
            DB::transaction(function () use ($actor, $organization, $prepared): void {
                foreach ($prepared as [$data, $name, $type, $ruleDefinition, $normalizedClientIds]) {
                    $this->persist($actor, $organization, $data, $name, $type, $ruleDefinition, $normalizedClientIds);
                }
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['name' => 'В этой организации уже есть группа с таким названием.']);
        }
    }

    /** @param array<string, mixed> $data
     * @param  list<int>  $clientIds
     * @return array{0: string, 1: string, 2: array<string, mixed>|null, 3: list<int>}
     */
    private function prepare(array $data, array $clientIds): array
    {
        $name = trim((string) ($data['name'] ?? ''));
        $type = (string) ($data['group_type'] ?? 'manual');
        if ($name === '' || mb_strlen($name) > 160) {
            throw ValidationException::withMessages(['name' => 'Название группы обязательно и не должно быть длиннее 160 символов.']);
        }
        if (filled($data['description'] ?? null) && mb_strlen(trim((string) $data['description'])) > 2000) {
            throw ValidationException::withMessages(['description' => 'Описание группы не должно быть длиннее 2000 символов.']);
        }
        if (! in_array($type, ['manual', 'dynamic'], true)) {
            throw ValidationException::withMessages(['group_type' => 'Выберите корректный тип группы.']);
        }
        $ruleDefinition = null;
        if ($type === 'dynamic') {
            try {
                $ruleDefinition = ClientCohortFilters::fromArray((array) ($data['rule_definition'] ?? []))->toArray();
            } catch (\InvalidArgumentException $exception) {
                throw ValidationException::withMessages(['rule_definition' => $exception->getMessage()]);
            }
            if (($ruleDefinition['saved_group_ids'] ?? []) !== []) {
                throw ValidationException::withMessages(['rule_definition' => 'Динамическая группа не может включать другую сохранённую группу.']);
            }
        }
        $normalizedClientIds = array_values(array_unique(array_filter(array_map('intval', $clientIds), static fn (int $id): bool => $id > 0)));

        return [$name, $type, $ruleDefinition, $normalizedClientIds];
    }

    /** @param array<string, mixed> $data
     * @param  array<string, mixed>|null  $ruleDefinition
     * @param  list<int>  $normalizedClientIds
     */
    private function persist(
        User $actor,
        Organization $organization,
        array $data,
        string $name,
        string $type,
        ?array $ruleDefinition,
        array $normalizedClientIds,
    ): AnalyticsClientGroup {
        $id = filter_var($data['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $group = $id === false
            ? new AnalyticsClientGroup
            : AnalyticsClientGroup::query()->where('organization_id', $organization->getKey())->whereKey($id)->lockForUpdate()->first();
        if ($id !== false && ! $group instanceof AnalyticsClientGroup) {
            throw ValidationException::withMessages(['id' => 'Выбранная группа недоступна в этой организации.']);
        }
        $expectedVersion = filter_var($data['version'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($group->exists && $expectedVersion !== false && (int) $group->version !== (int) $expectedVersion) {
            throw ValidationException::withMessages(['version' => 'Группа была изменена в другом окне. Обновите страницу и повторите попытку.']);
        }
        $duplicate = $this->hasDuplicateName(
            $organization,
            $name,
            $group->exists ? (int) $group->getKey() : null,
        );
        if ($duplicate) {
            throw ValidationException::withMessages(['name' => 'В этой организации уже есть группа с таким названием.']);
        }
        $group->forceFill([
            'organization_id' => $organization->getKey(),
            'name' => $name,
            'description' => filled($data['description'] ?? null) ? trim((string) $data['description']) : null,
            'group_type' => $type,
            'is_active' => (bool) ($data['is_active'] ?? true),
            'sort_order' => max(0, (int) ($data['sort_order'] ?? 0)),
            'version' => $group->exists ? ((int) $group->version + 1) : 1,
            'rule_definition' => $ruleDefinition,
            'created_by_user_id' => $group->created_by_user_id ?? $actor->getKey(),
            'updated_by_user_id' => $actor->getKey(),
            'archived_at' => ($data['is_active'] ?? true) ? null : ($group->archived_at ?? now()),
        ])->save();
        $storedClientIds = [];
        if ($type === 'manual') {
            $validClientIds = $this->organizationClientIds($organization, $normalizedClientIds);
            $storedClientIds = array_map('intval', $validClientIds);
            $group->clients()->syncWithPivotValues($validClientIds, [
                'organization_id' => $organization->getKey(),
                'added_by_user_id' => $actor->getKey(),
            ]);
        } else {
            $this->assertScopedReferences($organization, $ruleDefinition);
            $group->clients()->detach();
        }
        AnalyticsClientGroupVersion::query()->create([
            'organization_id' => $organization->getKey(),
            'analytics_client_group_id' => $group->getKey(),
            'version' => $group->version,
            'name' => $group->name,
            'description' => $group->description,
            'group_type' => $group->group_type,
            'is_active' => $group->is_active,
            'sort_order' => $group->sort_order,
            'rule_definition' => $group->rule_definition,
            'client_ids' => $storedClientIds,
            'created_by_user_id' => $actor->getKey(),
        ]);
        $this->audit->handle(
            organization: $organization,
            actor: $actor,
            action: 'analytics.client_group.updated',
            targetType: AnalyticsClientGroup::class,
            targetId: (string) $group->getKey(),
            metadata: ['group_type' => $type, 'version' => $group->version],
        );

        return $group->refresh();
    }

    /** @param array<string, mixed>|null $ruleDefinition
     * @param  list<int>  $normalizedClientIds
     */
    private function assertReferencesBelongToOrganization(
        Organization $organization,
        string $type,
        ?array $ruleDefinition,
        array $normalizedClientIds,
    ): void {
        if ($type === 'manual') {
            $this->organizationClientIds($organization, $normalizedClientIds);

            return;
        }

        $this->assertScopedReferences($organization, $ruleDefinition);
    }

    /** @param list<int> $normalizedClientIds
     * @return list<int>
     */
    private function organizationClientIds(Organization $organization, array $normalizedClientIds): array
    {
        if ($normalizedClientIds === []) {
            return [];
        }

        $validClientIds = Client::query()
            ->where('organization_id', $organization->getKey())
            ->whereIn('id', $normalizedClientIds)
            ->pluck('id')
            ->all();
        if (count($validClientIds) !== count($normalizedClientIds)) {
            throw ValidationException::withMessages(['client_ids' => 'Все выбранные клиенты должны принадлежать текущей организации.']);
        }

        return array_map('intval', $validClientIds);
    }

    /** @param array<string, mixed>|null $ruleDefinition */
    private function assertScopedReferences(Organization $organization, ?array $ruleDefinition): void
    {
        if ($ruleDefinition === null) {
            return;
        }

        $references = [
            'problem_tag_ids' => MedicalProblemTag::class,
            'exclude_problem_tag_ids' => MedicalProblemTag::class,
            'feedback_theme_ids' => FeedbackTheme::class,
            'goal_tag_ids' => TrackerGoalTag::class,
            'service_ids' => Service::class,
            'specialist_ids' => Specialist::class,
        ];
        foreach ($references as $field => $model) {
            $ids = array_values(array_unique(array_filter(array_map('intval', (array) ($ruleDefinition[$field] ?? [])), static fn (int $id): bool => $id > 0)));
            if ($ids === []) {
                continue;
            }
            $count = $model::query()
                ->where('organization_id', $organization->getKey())
                ->whereIn('id', $ids)
                ->count();
            if ($count !== count($ids)) {
                throw ValidationException::withMessages([$field => 'Все выбранные значения должны принадлежать текущей организации.']);
            }
        }
    }

    private function hasDuplicateName(Organization $organization, string $name, ?int $ignoreId): bool
    {
        $normalizedName = mb_strtolower(trim($name));

        return AnalyticsClientGroup::query()
            ->where('organization_id', $organization->getKey())
            ->when($ignoreId !== null, fn ($query) => $query->whereKeyNot($ignoreId))
            ->get(['id', 'name'])
            ->contains(static fn (AnalyticsClientGroup $group): bool => mb_strtolower(trim($group->name)) === $normalizedName);
    }
}
