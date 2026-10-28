<?php

namespace App\Modules\Analytics\Infrastructure;

use App\Modules\Analytics\Application\MarkAnalyticsClassificationsStale;
use App\Modules\Feedback\Domain\Models\FeedbackSubmission;
use App\Modules\Feedback\Domain\Models\FeedbackTheme;
use App\Modules\MedicalProfiles\Domain\Models\MedicalProblemTag;
use App\Modules\MedicalProfiles\Domain\Models\MedicalProfilePain;
use App\Modules\Sessions\Domain\Models\MedicalSessionPain;
use App\Modules\Tracker\Domain\Models\TrackerGoalTag;
use App\Modules\Tracker\Domain\Models\TrackerTask;
use Illuminate\Database\Eloquent\Model;

final class AnalyticsClassificationSourceObserver
{
    public function __construct(private readonly MarkAnalyticsClassificationsStale $stale) {}

    public function created(Model $model): void
    {
        $this->invalidate($model);
    }

    public function updated(Model $model): void
    {
        if ($model instanceof MedicalProfilePain && ! $model->wasChanged('name')) {
            return;
        }

        if ($model instanceof MedicalSessionPain && ! $model->wasChanged('name')) {
            return;
        }

        if ($model instanceof FeedbackSubmission && ! $model->wasChanged('internal_feedback')) {
            return;
        }

        if ($model instanceof TrackerTask && ! $model->wasChanged('title')) {
            return;
        }

        $this->invalidate($model);
    }

    public function deleted(Model $model): void
    {
        $this->invalidate($model);
    }

    private function invalidate(Model $model): void
    {
        $organizationId = (int) ($model->getAttribute('organization_id') ?? 0);
        if ($organizationId < 1) {
            return;
        }

        if ($model instanceof MedicalProfilePain) {
            $this->stale->forSource($organizationId, 'medical_profile_pain', (int) $model->getKey());

            return;
        }

        if ($model instanceof MedicalSessionPain) {
            $this->stale->forSource($organizationId, 'medical_session_pain', (int) $model->getKey());

            return;
        }

        if ($model instanceof FeedbackSubmission) {
            $this->stale->forSource($organizationId, 'feedback_submission', (int) $model->getKey());

            return;
        }

        if ($model instanceof TrackerTask) {
            $this->stale->forSource($organizationId, 'tracker_task', (int) $model->getKey());

            return;
        }

        if ($model instanceof MedicalProblemTag) {
            $this->stale->forTaxonomy($organizationId, 'problems');

            return;
        }

        if ($model instanceof FeedbackTheme) {
            $this->stale->forTaxonomy($organizationId, 'feedback');

            return;
        }

        if ($model instanceof TrackerGoalTag) {
            $this->stale->forTaxonomy($organizationId, 'goals');
        }
    }
}
