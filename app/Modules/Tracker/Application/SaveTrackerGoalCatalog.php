<?php

namespace App\Modules\Tracker\Application;

use App\Models\User;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use App\Modules\Security\Application\RecordAuditEvent;
use App\Modules\Tracker\Domain\Models\TrackerGoalTag;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final readonly class SaveTrackerGoalCatalog
{
    public function __construct(
        private OrganizationContext $context,
        private OrganizationAuthorizer $authorizer,
        private RecordAuditEvent $audit,
    ) {}

    /** @param array<int, mixed> $tags */
    public function handle(User $actor, array $tags): void
    {
        $organization = $this->context->organization();
        $this->authorizer->authorize($actor, $organization, OrganizationPermission::ManageSettings);
        $normalized = [];
        $names = [];
        foreach (array_values($tags) as $index => $raw) {
            if (! is_array($raw)) {
                throw ValidationException::withMessages(['goal_tags' => 'Проверьте категории задач.']);
            }
            $name = trim((string) ($raw['name'] ?? ''));
            if ($name === '' || mb_strlen($name) > 255 || isset($names[$name])) {
                throw ValidationException::withMessages(['goal_tags' => 'Названия категорий задач должны быть заполнены и уникальны.']);
            }
            $names[$name] = true;
            $normalized[] = [
                'name' => $name,
                'group_name' => filled($raw['group_name'] ?? $raw['groupName'] ?? null) ? trim((string) ($raw['group_name'] ?? $raw['groupName'])) : null,
                'is_active' => (bool) ($raw['is_active'] ?? $raw['isActive'] ?? true),
                'sort_order' => max(0, (int) ($raw['sort_order'] ?? $raw['sortOrder'] ?? $index)),
            ];
        }

        DB::transaction(function () use ($organization, $actor, $normalized, $names): void {
            foreach ($normalized as $tag) {
                $model = TrackerGoalTag::query()
                    ->where('organization_id', $organization->getKey())
                    ->where('name', $tag['name'])
                    ->lockForUpdate()
                    ->first() ?? new TrackerGoalTag;
                $model->forceFill([
                    'organization_id' => $organization->getKey(),
                    ...$tag,
                ])->save();
            }
            TrackerGoalTag::query()
                ->where('organization_id', $organization->getKey())
                ->when($names !== [], fn ($query) => $query->whereNotIn('name', array_keys($names)))
                ->update(['is_active' => false]);
            $this->audit->handle(
                organization: $organization,
                actor: $actor,
                action: 'tracker.goal_catalog.updated',
                targetType: TrackerGoalTag::class,
                targetId: 'tracker-goal-catalog',
                metadata: ['tag_count' => count($normalized)],
            );
        });
    }
}
