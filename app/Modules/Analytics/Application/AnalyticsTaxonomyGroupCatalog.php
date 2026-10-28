<?php

namespace App\Modules\Analytics\Application;

use App\Modules\Analytics\Domain\Enums\AnalyticsTaxonomy;
use App\Modules\Analytics\Domain\Models\AnalyticsTaxonomyGroup;
use App\Modules\Feedback\Domain\Models\FeedbackTheme;
use App\Modules\MedicalProfiles\Domain\Models\MedicalProblemTag;
use App\Modules\Sessions\Domain\Models\MedicalCauseDefinition;
use App\Modules\Tracker\Domain\Models\TrackerGoalTag;

final class AnalyticsTaxonomyGroupCatalog
{
    /** @return array<string, string> */
    public function options(int $organizationId, AnalyticsTaxonomy $taxonomy): array
    {
        return collect($this->rows($organizationId, $taxonomy))
            ->filter(static fn (array $row): bool => $row['is_active'])
            ->sortBy(['sort_order', 'name'])
            ->mapWithKeys(static fn (array $row): array => [(string) $row['name'] => (string) $row['name']])
            ->all();
    }

    /** @return array<string, list<array{id:int|null,original_name:string|null,name:string,is_active:bool,sort_order:int}>> */
    public function all(int $organizationId): array
    {
        return array_reduce(
            AnalyticsTaxonomy::cases(),
            fn (array $result, AnalyticsTaxonomy $taxonomy): array => [...$result, $taxonomy->value => $this->rows($organizationId, $taxonomy)],
            [],
        );
    }

    /** @return list<array{id:int|null,original_name:string|null,name:string,is_active:bool,sort_order:int}> */
    private function rows(int $organizationId, AnalyticsTaxonomy $taxonomy): array
    {
        $models = AnalyticsTaxonomyGroup::query()
            ->where('organization_id', $organizationId)
            ->where('taxonomy', $taxonomy->value)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get(['id', 'name', 'is_active', 'sort_order']);
        $groups = [];
        $known = [];
        foreach ($models as $group) {
            $name = (string) $group->name;
            $groups[] = [
                'id' => (int) $group->getKey(),
                'original_name' => $name,
                'name' => $name,
                'is_active' => (bool) $group->is_active,
                'sort_order' => (int) $group->sort_order,
            ];
            $known[] = mb_strtolower($name);
        }
        foreach ($this->legacyNames($organizationId, $taxonomy) as $name) {
            if (in_array(mb_strtolower($name), $known, true)) {
                continue;
            }
            $groups[] = [
                'id' => null,
                'original_name' => $name,
                'name' => $name,
                'is_active' => true,
                'sort_order' => count($groups),
            ];
            $known[] = mb_strtolower($name);
        }

        return $groups;
    }

    /** @return list<string> */
    private function legacyNames(int $organizationId, AnalyticsTaxonomy $taxonomy): array
    {
        $model = match ($taxonomy) {
            AnalyticsTaxonomy::Problems => MedicalProblemTag::class,
            AnalyticsTaxonomy::Causes => MedicalCauseDefinition::class,
            AnalyticsTaxonomy::Goals => TrackerGoalTag::class,
            AnalyticsTaxonomy::Feedback => FeedbackTheme::class,
        };

        $names = $model::query()
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
