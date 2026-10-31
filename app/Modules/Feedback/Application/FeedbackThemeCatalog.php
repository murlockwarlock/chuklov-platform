<?php

namespace App\Modules\Feedback\Application;

use App\Modules\Feedback\Domain\Models\FeedbackTheme;
use Illuminate\Validation\ValidationException;

final class FeedbackThemeCatalog
{
    /** @return array<int, string> */
    public function options(int $organizationId): array
    {
        return FeedbackTheme::query()
            ->where('organization_id', $organizationId)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get(['id', 'name', 'group_name'])
            ->mapWithKeys(static fn (FeedbackTheme $theme): array => [
                $theme->getKey() => filled($theme->group_name) ? $theme->group_name.': '.$theme->name : $theme->name,
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

        FeedbackTheme::query()
            ->where('organization_id', $organizationId)
            ->whereIn('id', $ids)
            ->where('is_active', false)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get(['id', 'name', 'group_name'])
            ->each(function (FeedbackTheme $theme) use (&$options): void {
                $label = filled($theme->group_name) ? $theme->group_name.': '.$theme->name : $theme->name;
                $options[(int) $theme->getKey()] = $label.' ('.__('неактивна').')';
            });

        return $options;
    }

    /**
     * @param  array<int, mixed>  $themeIds
     * @param  array<int, mixed>  $preserveInactiveIds
     * @return list<int>
     */
    public function assertKnown(int $organizationId, array $themeIds, array $preserveInactiveIds = []): array
    {
        $ids = array_values(array_unique(array_map(static fn (mixed $id): int => (int) $id, $themeIds)));
        $preserved = array_values(array_unique(array_map(static fn (mixed $id): int => (int) $id, $preserveInactiveIds)));
        if ($ids === []) {
            return [];
        }
        if (count($ids) > 20 || in_array(0, $ids, true)) {
            throw ValidationException::withMessages(['feedback_themes' => 'Выберите не более 20 корректных тем.']);
        }

        $known = FeedbackTheme::query()
            ->where('organization_id', $organizationId)
            ->whereIn('id', $ids)
            ->pluck('id')
            ->map(static fn (int|string $id): int => (int) $id)
            ->all();
        $active = FeedbackTheme::query()
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
            throw ValidationException::withMessages(['feedback_themes' => 'Выбранная тема недоступна для этой организации.']);
        }

        return $allowed;
    }
}
