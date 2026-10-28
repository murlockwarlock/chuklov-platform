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

    /** @param array<int, mixed> $themeIds */
    public function assertKnown(int $organizationId, array $themeIds): array
    {
        $ids = array_values(array_unique(array_map(static fn (mixed $id): int => (int) $id, $themeIds)));
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
        sort($known);
        sort($ids);
        if ($known !== $ids) {
            throw ValidationException::withMessages(['feedback_themes' => 'Выбранная тема недоступна для этой организации.']);
        }

        return $known;
    }
}
