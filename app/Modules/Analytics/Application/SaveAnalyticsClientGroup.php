<?php

namespace App\Modules\Analytics\Application;

use App\Models\User;
use App\Modules\Analytics\Application\Data\ClientCohortFilters;
use App\Modules\Analytics\Domain\Models\AnalyticsClientGroup;
use App\Modules\Analytics\Domain\Models\AnalyticsClientGroupVersion;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use App\Modules\Security\Application\RecordAuditEvent;
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

        return DB::transaction(function () use ($actor, $organization, $data, $name, $type, $ruleDefinition, $normalizedClientIds): AnalyticsClientGroup {
            $id = filter_var($data['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            $group = $id === false
                ? new AnalyticsClientGroup
                : AnalyticsClientGroup::query()->where('organization_id', $organization->getKey())->whereKey($id)->lockForUpdate()->firstOrFail();
            $duplicate = AnalyticsClientGroup::query()
                ->where('organization_id', $organization->getKey())
                ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
                ->when($group->exists, fn ($query) => $query->where('id', '!=', $group->getKey()))
                ->exists();
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
                $validClientIds = Client::query()
                    ->where('organization_id', $organization->getKey())
                    ->whereIn('id', $normalizedClientIds)
                    ->pluck('id')
                    ->all();
                $storedClientIds = array_map('intval', $validClientIds);
                $group->clients()->syncWithPivotValues($validClientIds, [
                    'organization_id' => $organization->getKey(),
                    'added_by_user_id' => $actor->getKey(),
                ]);
            } else {
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
        });
    }
}
