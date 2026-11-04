<?php

namespace App\Modules\Analytics\Application;

use App\Modules\Analytics\Domain\Enums\AnalyticsTagCandidateStatus;
use App\Modules\Analytics\Domain\Enums\ClassificationRunStatus;
use App\Modules\Analytics\Domain\Models\AnalyticsClassificationRun;
use App\Modules\Analytics\Domain\Models\AnalyticsTagCandidate;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class MarkAnalyticsClassificationsStale
{
    public function forSource(int $organizationId, string $sourceType, int $sourceId): int
    {
        if ($organizationId < 1 || trim($sourceType) === '' || $sourceId < 1) {
            return 0;
        }

        return DB::transaction(function () use ($organizationId, $sourceType, $sourceId): int {
            $updated = AnalyticsClassificationRun::query()
                ->where('organization_id', $organizationId)
                ->where('source_type', trim($sourceType))
                ->where('source_id', $sourceId)
                ->where('status', '!=', ClassificationRunStatus::Stale->value)
                ->update($this->stalePayload());
            $this->markCandidatesStale($organizationId, $sourceType, $sourceId);

            return $updated;
        });
    }

    public function forTaxonomy(int $organizationId, string $taxonomy, ?int $exceptRunId = null): int
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

        return DB::transaction(function () use ($organizationId, $taxonomies, $exceptRunId): int {
            $updated = AnalyticsClassificationRun::query()
                ->where('organization_id', $organizationId)
                ->whereIn('taxonomy', array_values(array_unique($taxonomies)))
                ->when($exceptRunId !== null, fn ($query) => $query->whereKeyNot($exceptRunId))
                ->where('status', '!=', ClassificationRunStatus::Stale->value)
                ->update($this->stalePayload());
            $this->markCandidatesStaleForTaxonomies($organizationId, $taxonomies, $exceptRunId);

            return $updated;
        });
    }

    private function markCandidatesStale(int $organizationId, string $sourceType, int $sourceId): void
    {
        AnalyticsTagCandidate::query()
            ->where('organization_id', $organizationId)
            ->where('status', AnalyticsTagCandidateStatus::NeedsReview->value)
            ->whereHas('classificationRun', function ($query) use ($sourceType, $sourceId): void {
                $query->where('source_type', trim($sourceType))->where('source_id', $sourceId);
            })
            ->update([
                'status' => AnalyticsTagCandidateStatus::Stale->value,
                'review_note' => 'Источник классификации изменился.',
                'updated_at' => now(),
            ]);
    }

    /** @param list<string> $taxonomies */
    private function markCandidatesStaleForTaxonomies(int $organizationId, array $taxonomies, ?int $exceptRunId = null): void
    {
        AnalyticsTagCandidate::query()
            ->where('organization_id', $organizationId)
            ->where('status', AnalyticsTagCandidateStatus::NeedsReview->value)
            ->whereHas('classificationRun', function ($query) use ($taxonomies, $exceptRunId): void {
                $query->whereIn('taxonomy', $taxonomies)
                    ->when($exceptRunId !== null, fn ($query) => $query->whereKeyNot($exceptRunId));
            })
            ->update([
                'status' => AnalyticsTagCandidateStatus::Stale->value,
                'review_note' => 'Таксономия классификации изменилась.',
                'updated_at' => now(),
            ]);
    }

    /** @return array{status: string, suggestions: null, error_code: string, error_message_sanitized: string, updated_at: Carbon} */
    private function stalePayload(): array
    {
        return [
            'status' => ClassificationRunStatus::Stale->value,
            'suggestions' => null,
            'error_code' => 'source_changed',
            'error_message_sanitized' => 'Источник классификации изменился.',
            'processing_lease_token' => null,
            'processing_lease_expires_at' => null,
            'updated_at' => now(),
        ];
    }
}
