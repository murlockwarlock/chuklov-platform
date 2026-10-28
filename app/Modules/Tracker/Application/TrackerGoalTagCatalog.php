<?php

namespace App\Modules\Tracker\Application;

use App\Modules\Tracker\Domain\Models\TrackerGoalTag;
use Illuminate\Validation\ValidationException;

final class TrackerGoalTagCatalog
{
    /** @return array<int, string> */
    public function options(int $organizationId): array
    {
        return TrackerGoalTag::query()
            ->where('organization_id', $organizationId)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get(['id', 'name', 'group_name'])
            ->mapWithKeys(static fn (TrackerGoalTag $tag): array => [
                $tag->getKey() => filled($tag->group_name) ? $tag->group_name.': '.$tag->name : $tag->name,
            ])
            ->all();
    }

    /** @param array<int, mixed> $tagIds */
    public function assertKnown(int $organizationId, array $tagIds): array
    {
        $ids = array_values(array_unique(array_map(static fn (mixed $id): int => (int) $id, $tagIds)));
        if ($ids === []) {
            return [];
        }
        if (count($ids) > 20 || in_array(0, $ids, true)) {
            throw ValidationException::withMessages(['goal_tag_ids' => 'Выберите не более 20 корректных категорий задач.']);
        }
        $known = TrackerGoalTag::query()
            ->where('organization_id', $organizationId)
            ->whereIn('id', $ids)
            ->pluck('id')
            ->map(static fn (int|string $id): int => (int) $id)
            ->all();
        sort($known);
        sort($ids);
        if ($known !== $ids) {
            throw ValidationException::withMessages(['goal_tag_ids' => 'Выбранная категория задачи недоступна для этой организации.']);
        }

        return $known;
    }
}
