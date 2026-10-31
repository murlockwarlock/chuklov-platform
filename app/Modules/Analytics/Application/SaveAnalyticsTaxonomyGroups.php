<?php

namespace App\Modules\Analytics\Application;

use App\Models\User;
use App\Modules\Analytics\Domain\Enums\AnalyticsTaxonomy;
use App\Modules\Analytics\Domain\Models\AnalyticsTaxonomyGroup;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use App\Modules\Security\Application\RecordAuditEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final readonly class SaveAnalyticsTaxonomyGroups
{
    public function __construct(
        private OrganizationContext $context,
        private OrganizationAuthorizer $authorizer,
        private RecordAuditEvent $audit,
        private MarkAnalyticsClassificationsStale $stale,
    ) {}

    /** @param array<string, mixed> $groupsByTaxonomy */
    public function handle(User $actor, array $groupsByTaxonomy): void
    {
        $organization = $this->context->organization();
        $this->authorizer->authorize($actor, $organization, OrganizationPermission::ManageSettings);
        $normalized = $this->normalize($groupsByTaxonomy);

        DB::transaction(function () use ($organization, $actor, $normalized): void {
            $total = 0;
            foreach ($normalized as $taxonomy => $groups) {
                $total += count($groups);
                $taxonomyEnum = AnalyticsTaxonomy::from($taxonomy);
                $this->saveTaxonomy($organization->getKey(), $taxonomyEnum, $groups);
                $this->stale->forTaxonomy((int) $organization->getKey(), $taxonomyEnum->value);
            }
            $this->audit->handle(
                organization: $organization,
                actor: $actor,
                action: 'analytics.taxonomy_groups.updated',
                targetType: AnalyticsTaxonomyGroup::class,
                targetId: 'analytics-taxonomy-groups',
                metadata: ['taxonomy_count' => count($normalized), 'group_count' => $total],
            );
        });
    }

    /** @param list<array{id:int|null,original_name:string|null,name:string,is_active:bool,sort_order:int}> $groups */
    private function saveTaxonomy(int $organizationId, AnalyticsTaxonomy $taxonomy, array $groups): void
    {
        $names = [];
        foreach ($groups as $group) {
            $key = mb_strtolower($group['name']);
            if (isset($names[$key])) {
                throw ValidationException::withMessages(['groups' => 'Названия групп внутри раздела должны быть уникальными.']);
            }
            $names[$key] = true;

            $query = AnalyticsTaxonomyGroup::query()
                ->where('organization_id', $organizationId)
                ->where('taxonomy', $taxonomy->value);
            if ((clone $query)
                ->whereRaw('LOWER(name) = ?', [$key])
                ->when($group['id'] !== null, fn ($duplicateQuery) => $duplicateQuery->where('id', '!=', $group['id']))
                ->exists()) {
                throw ValidationException::withMessages(['groups' => 'В разделе уже есть группа с таким названием.']);
            }
            $model = $group['id'] !== null
                ? $query->whereKey($group['id'])->lockForUpdate()->firstOrFail()
                : $query->whereRaw('LOWER(name) = ?', [$key])->lockForUpdate()->first();
            $oldName = $model instanceof AnalyticsTaxonomyGroup ? (string) $model->name : null;
            if (! $model instanceof AnalyticsTaxonomyGroup) {
                $model = new AnalyticsTaxonomyGroup;
                if ($group['original_name'] !== null && $group['original_name'] !== $group['name']) {
                    $this->renameReferences($organizationId, $taxonomy, $group['original_name'], $group['name']);
                }
            }
            $model->forceFill([
                'organization_id' => $organizationId,
                'taxonomy' => $taxonomy->value,
                'name' => $group['name'],
                'is_active' => $group['is_active'],
                'sort_order' => $group['sort_order'],
            ])->save();
            if ($oldName !== null && $oldName !== $group['name']) {
                $this->renameReferences($organizationId, $taxonomy, $oldName, $group['name']);
            }
        }

        $existing = AnalyticsTaxonomyGroup::query()
            ->where('organization_id', $organizationId)
            ->where('taxonomy', $taxonomy->value);
        foreach ($existing->lockForUpdate()->get() as $group) {
            if (! isset($names[mb_strtolower((string) $group->name)])) {
                $group->forceFill(['is_active' => false])->save();
            }
        }
        $known = AnalyticsTaxonomyGroup::query()
            ->where('organization_id', $organizationId)
            ->where('taxonomy', $taxonomy->value)
            ->pluck('name')
            ->map(static fn (mixed $name): string => mb_strtolower((string) $name))
            ->all();
        foreach ($this->legacyReferenceNames($organizationId, $taxonomy) as $legacyName) {
            $legacyKey = mb_strtolower($legacyName);
            if (isset($names[$legacyKey]) || in_array($legacyKey, $known, true)) {
                continue;
            }
            AnalyticsTaxonomyGroup::query()->create([
                'organization_id' => $organizationId,
                'taxonomy' => $taxonomy->value,
                'name' => $legacyName,
                'is_active' => false,
                'sort_order' => count($known),
            ]);
            $known[] = $legacyKey;
        }
    }

    /**
     * @param  array<string, mixed>  $groupsByTaxonomy
     * @return array<string, list<array{id:int|null,original_name:string|null,name:string,is_active:bool,sort_order:int}>>
     */
    private function normalize(array $groupsByTaxonomy): array
    {
        $normalized = [];
        foreach (AnalyticsTaxonomy::cases() as $taxonomy) {
            $values = $groupsByTaxonomy[$taxonomy->value] ?? [];
            if (! is_array($values)) {
                throw ValidationException::withMessages(['groups' => 'Проверьте группы аналитики.']);
            }
            $normalized[$taxonomy->value] = [];
            foreach (array_values($values) as $index => $value) {
                if (! is_array($value)) {
                    throw ValidationException::withMessages(['groups' => 'Проверьте группы аналитики.']);
                }
                $name = trim((string) ($value['name'] ?? ''));
                if ($name === '' || mb_strlen($name) > 255) {
                    throw ValidationException::withMessages(['groups' => 'Название группы обязательно и не должно быть длиннее 255 символов.']);
                }
                $id = filter_var($value['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                $sortOrder = filter_var($value['sort_order'] ?? $value['sortOrder'] ?? $index, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
                if ($sortOrder === false) {
                    throw ValidationException::withMessages(['groups' => 'Порядок группы указан неверно.']);
                }
                $normalized[$taxonomy->value][] = [
                    'id' => $id === false ? null : (int) $id,
                    'original_name' => filled($value['original_name'] ?? null) ? trim((string) $value['original_name']) : null,
                    'name' => $name,
                    'is_active' => (bool) ($value['is_active'] ?? $value['isActive'] ?? true),
                    'sort_order' => (int) $sortOrder,
                ];
            }
        }

        return $normalized;
    }

    private function renameReferences(int $organizationId, AnalyticsTaxonomy $taxonomy, string $oldName, string $newName): void
    {
        $model = match ($taxonomy) {
            AnalyticsTaxonomy::Problems => 'medical_problem_tags',
            AnalyticsTaxonomy::Causes => 'medical_cause_definitions',
            AnalyticsTaxonomy::Goals => 'tracker_goal_tags',
            AnalyticsTaxonomy::Feedback => 'feedback_themes',
        };
        DB::table($model)
            ->where('organization_id', $organizationId)
            ->where('group_name', $oldName)
            ->update(['group_name' => $newName, 'updated_at' => now()]);
    }

    /** @return list<string> */
    private function legacyReferenceNames(int $organizationId, AnalyticsTaxonomy $taxonomy): array
    {
        $table = match ($taxonomy) {
            AnalyticsTaxonomy::Problems => 'medical_problem_tags',
            AnalyticsTaxonomy::Causes => 'medical_cause_definitions',
            AnalyticsTaxonomy::Goals => 'tracker_goal_tags',
            AnalyticsTaxonomy::Feedback => 'feedback_themes',
        };
        $names = DB::table($table)
            ->where('organization_id', $organizationId)
            ->whereNotNull('group_name')
            ->where('group_name', '!=', '')
            ->distinct()
            ->orderBy('group_name')
            ->pluck('group_name')
            ->map(static fn (mixed $name): string => trim((string) $name))
            ->filter()
            ->values()
            ->all();

        return array_values($names);
    }
}
