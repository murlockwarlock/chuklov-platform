<?php

namespace App\Modules\Analytics\Application;

use App\Modules\Analytics\Domain\Enums\AnalyticsTagCandidateStatus;
use App\Modules\Analytics\Domain\Models\AnalyticsTagCandidate;

final class AnalyticsTagCandidateCatalog
{
    /** @return list<array<string, mixed>> */
    public function pending(int $organizationId): array
    {
        $candidates = AnalyticsTagCandidate::query()
            ->where('organization_id', $organizationId)
            ->where('status', AnalyticsTagCandidateStatus::NeedsReview)
            ->orderByDesc('created_at')
            ->get();
        $rows = [];
        foreach ($candidates as $candidate) {
            $rows[] = [
                'id' => (int) $candidate->getKey(),
                'taxonomy' => (string) $candidate->taxonomy,
                'source_type' => (string) $candidate->source_type,
                'label' => (string) $candidate->label,
                'candidate_type' => (string) $candidate->candidate_type,
                'status' => $candidate->status->value,
            ];
        }

        return $rows;
    }
}
