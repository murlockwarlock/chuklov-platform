<?php

namespace App\Modules\Analytics\Application;

use App\Modules\Analytics\Domain\Enums\AnalyticsTagCandidateStatus;
use App\Modules\Analytics\Domain\Models\AnalyticsClassificationRun;
use App\Modules\Analytics\Domain\Models\AnalyticsTagCandidate;
use App\Modules\Organizations\Domain\Enums\OrganizationSettingKey;
use App\Modules\Organizations\Domain\Models\OrganizationSetting;
use Illuminate\Support\Facades\DB;

final class SyncAnalyticsClassificationCandidates
{
    public function handle(AnalyticsClassificationRun $run): void
    {
        $suggestions = is_array($run->suggestions) ? $run->suggestions : [];
        $allowNew = $this->newCandidatesEnabled((int) $run->organization_id);
        $labels = [];

        DB::transaction(function () use ($run, $suggestions, $allowNew, &$labels): void {
            $lockedRun = AnalyticsClassificationRun::query()
                ->where('organization_id', $run->organization_id)
                ->whereKey($run->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            foreach ($suggestions as $suggestion) {
                $label = trim($suggestion['label']);
                if ($label === '' || mb_strlen($label) > 255) {
                    continue;
                }
                $existingId = filter_var($suggestion['id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                $candidateType = $existingId !== false ? 'existing' : 'new';
                if ($candidateType === 'new' && ! $allowNew) {
                    continue;
                }
                $labels[] = $label;
                $candidate = AnalyticsTagCandidate::query()
                    ->where('organization_id', $lockedRun->organization_id)
                    ->where('classification_run_id', $lockedRun->getKey())
                    ->where('candidate_type', $candidateType)
                    ->where('label', $label)
                    ->lockForUpdate()
                    ->first() ?? new AnalyticsTagCandidate;
                if ($candidate->exists && $candidate->status !== AnalyticsTagCandidateStatus::NeedsReview) {
                    continue;
                }
                $candidate->forceFill([
                    'organization_id' => $lockedRun->organization_id,
                    'classification_run_id' => $lockedRun->getKey(),
                    'client_id' => $lockedRun->client_id,
                    'taxonomy' => $lockedRun->taxonomy,
                    'source_type' => $lockedRun->source_type,
                    'source_id' => $lockedRun->source_id,
                    'candidate_type' => $candidateType,
                    'suggested_existing_id' => $existingId !== false ? (int) $existingId : null,
                    'label' => $label,
                    'status' => AnalyticsTagCandidateStatus::NeedsReview,
                    'version' => $candidate->exists ? ((int) $candidate->version + 1) : 1,
                ])->save();
            }

            AnalyticsTagCandidate::query()
                ->where('organization_id', $lockedRun->organization_id)
                ->where('classification_run_id', $lockedRun->getKey())
                ->where('status', AnalyticsTagCandidateStatus::NeedsReview)
                ->when($labels !== [], fn ($query) => $query->whereNotIn('label', $labels))
                ->update([
                    'status' => AnalyticsTagCandidateStatus::Stale->value,
                    'review_note' => 'Предложение исчезло после изменения источника.',
                    'updated_at' => now(),
                ]);
        });
    }

    private function newCandidatesEnabled(int $organizationId): bool
    {
        $setting = OrganizationSetting::query()
            ->where('organization_id', $organizationId)
            ->where('setting_key', OrganizationSettingKey::AnalyticsAiNewTagCandidatesEnabled->value)
            ->first();

        return $setting?->boolean_value === true;
    }
}
