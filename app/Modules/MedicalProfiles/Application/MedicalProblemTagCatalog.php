<?php

namespace App\Modules\MedicalProfiles\Application;

use App\Modules\MedicalProfiles\Domain\Models\MedicalProblemTag;
use Illuminate\Validation\ValidationException;

final class MedicalProblemTagCatalog
{
    /** @return array<int, string> */
    public function options(int $organizationId): array
    {
        return MedicalProblemTag::query()
            ->where('organization_id', $organizationId)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get(['id', 'name', 'group_name'])
            ->mapWithKeys(static fn (MedicalProblemTag $tag): array => [
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
            throw ValidationException::withMessages(['pains' => 'Выберите не более 20 корректных категорий проблемы.']);
        }

        $active = MedicalProblemTag::query()
            ->where('organization_id', $organizationId)
            ->whereIn('id', $ids)
            ->pluck('id')
            ->map(static fn (int|string $id): int => (int) $id)
            ->all();

        sort($active);
        sort($ids);
        if ($active !== $ids) {
            throw ValidationException::withMessages(['pains' => 'Выбранная категория проблемы недоступна для этой организации.']);
        }

        return $active;
    }
}
