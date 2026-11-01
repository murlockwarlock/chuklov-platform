<?php

namespace App\Modules\Analytics\Application;

use App\Modules\Analytics\Domain\Enums\AnalyticsTagCandidateStatus;
use App\Modules\Analytics\Domain\Models\AnalyticsTagCandidate;
use App\Modules\Identity\Domain\Models\Client;

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
        $clientLabels = Client::query()
            ->where('organization_id', $organizationId)
            ->whereIn('id', $candidates->pluck('client_id')->filter()->unique()->values())
            ->pluck('full_name', 'id');
        $rows = [];
        foreach ($candidates as $candidate) {
            $rows[] = [
                'id' => (int) $candidate->getKey(),
                'taxonomy' => (string) $candidate->taxonomy,
                'source_type' => (string) $candidate->source_type,
                'source_id' => (int) $candidate->source_id,
                'client_label' => $candidate->client_id !== null ? (string) ($clientLabels->get($candidate->client_id) ?? '') : null,
                'label' => (string) $candidate->label,
                'candidate_type' => (string) $candidate->candidate_type,
                'status' => $candidate->status->value,
            ];
        }

        return $rows;
    }
}
