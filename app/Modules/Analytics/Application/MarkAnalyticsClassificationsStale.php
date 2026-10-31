<?php

namespace App\Modules\Analytics\Application;

use App\Modules\Analytics\Domain\Enums\ClassificationRunStatus;
use App\Modules\Analytics\Domain\Models\AnalyticsClassificationRun;
use Illuminate\Support\Carbon;

final class MarkAnalyticsClassificationsStale
{
    public function forSource(int $organizationId, string $sourceType, int $sourceId): int
    {
        if ($organizationId < 1 || trim($sourceType) === '' || $sourceId < 1) {
            return 0;
        }

        return AnalyticsClassificationRun::query()
            ->where('organization_id', $organizationId)
            ->where('source_type', trim($sourceType))
            ->where('source_id', $sourceId)
            ->where('status', '!=', ClassificationRunStatus::Stale->value)
            ->update($this->stalePayload());
    }

    public function forTaxonomy(int $organizationId, string $taxonomy): int
    {
        if ($organizationId < 1 || trim($taxonomy) === '') {
            return 0;
        }

        $taxonomies = [$taxonomy = trim($taxonomy)];
        if ($taxonomy === 'problems') {
            $taxonomies[] = 'complaints';
        } elseif ($taxonomy === 'complaints') {
            $taxonomies[] = 'problems';
        }

        return AnalyticsClassificationRun::query()
            ->where('organization_id', $organizationId)
            ->whereIn('taxonomy', array_values(array_unique($taxonomies)))
            ->where('status', '!=', ClassificationRunStatus::Stale->value)
            ->update($this->stalePayload());
    }

    /** @return array{status: string, suggestions: null, error_code: string, error_message_sanitized: string, updated_at: Carbon} */
    private function stalePayload(): array
    {
        return [
            'status' => ClassificationRunStatus::Stale->value,
            'suggestions' => null,
            'error_code' => 'source_changed',
            'error_message_sanitized' => 'Источник классификации изменился.',
            'updated_at' => now(),
        ];
    }
}
