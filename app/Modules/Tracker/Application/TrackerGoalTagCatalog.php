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

    /** @param array<int, mixed> $selectedIds
     * @return array<int, string>
     */
    public function optionsForSelection(int $organizationId, array $selectedIds): array
    {
        $options = $this->options($organizationId);
        $ids = array_values(array_unique(array_map(static fn (mixed $id): int => (int) $id, $selectedIds)));
        if ($ids === []) {
            return $options;
        }

        TrackerGoalTag::query()
            ->where('organization_id', $organizationId)
            ->whereIn('id', $ids)
            ->where('is_active', false)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get(['id', 'name', 'group_name'])
            ->each(function (TrackerGoalTag $tag) use (&$options): void {
                $label = filled($tag->group_name) ? $tag->group_name.': '.$tag->name : $tag->name;
                $options[(int) $tag->getKey()] = $label.' ('.__('неактивна').')';
            });

        return $options;
    }

    /**
     * @param  array<int, mixed>  $tagIds
     * @param  array<int, mixed>  $preserveInactiveIds
     * @return list<int>
     */
    public function assertKnown(int $organizationId, array $tagIds, array $preserveInactiveIds = []): array
    {
        $ids = array_values(array_unique(array_map(static fn (mixed $id): int => (int) $id, $tagIds)));
        $preserved = array_values(array_unique(array_map(static fn (mixed $id): int => (int) $id, $preserveInactiveIds)));
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
        $active = TrackerGoalTag::query()
            ->where('organization_id', $organizationId)
            ->whereIn('id', $ids)
            ->where('is_active', true)
            ->pluck('id')
            ->map(static fn (int|string $id): int => (int) $id)
            ->all();
        $allowed = array_values(array_unique([...$active, ...array_intersect($known, $preserved)]));
        sort($allowed);
        sort($ids);
        if ($allowed !== $ids) {
            throw ValidationException::withMessages(['goal_tag_ids' => 'Выбранная категория задачи недоступна для этой организации.']);
        }

        return $allowed;
    }
}
