<?php

namespace App\Modules\Analytics\Infrastructure\Jobs;

use App\Models\User;
use App\Modules\Analytics\Application\SuggestAnalyticsTags;
use App\Modules\Feedback\Application\FeedbackThemeCatalog;
use App\Modules\Feedback\Domain\Models\FeedbackSubmission;
use App\Modules\MedicalProfiles\Application\MedicalProblemTagCatalog;
use App\Modules\MedicalProfiles\Application\MedicalProfilePainReader;
use App\Modules\MedicalProfiles\Domain\Models\MedicalProfilePain;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use App\Modules\Organizations\Domain\Enums\OrganizationSettingKey;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Sessions\Application\MedicalSessionPainReader;
use App\Modules\Sessions\Domain\Models\MedicalSessionPain;
use App\Modules\Tracker\Application\TrackerGoalTagCatalog;
use App\Modules\Tracker\Domain\Models\TrackerTask;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

final class AutoSuggestAnalyticsTags implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public function __construct(
        public readonly int $organizationId,
        public readonly string $sourceType,
        public readonly int $sourceId,
    ) {
        $this->onQueue('analytics-classification');
    }

    public function handle(
        OrganizationContext $context,
        SuggestAnalyticsTags $suggest,
        MedicalProfilePainReader $profilePains,
        MedicalSessionPainReader $sessionPains,
        MedicalProblemTagCatalog $problemTags,
        FeedbackThemeCatalog $feedbackThemes,
        TrackerGoalTagCatalog $goalTags,
    ): void {
        $organization = Organization::query()->whereKey($this->organizationId)->first();
        if ($organization === null || ! $this->automaticSuggestionsEnabled()) {
            return;
        }

        $actor = User::query()
            ->whereHas('memberships', fn ($query) => $query
                ->where('organization_id', $this->organizationId)
                ->where('is_active', true))
            ->get()
            ->first(fn (User $user): bool => $user->hasPermission(OrganizationPermission::ManageClients, $organization));
        if (! $actor instanceof User) {
            return;
        }

        $context->set($organization);
        $source = $this->source($profilePains, $sessionPains);
        if ($source === null || trim($source['text']) === '') {
            return;
        }

        $availableTags = match ($source['taxonomy']) {
            'complaints' => $this->options($problemTags->options($this->organizationId)),
            'feedback' => $this->options($feedbackThemes->options($this->organizationId)),
            'goals' => $this->options($goalTags->options($this->organizationId)),
            default => [],
        };

        $suggest->handle(
            actor: $actor,
            taxonomy: $source['taxonomy'],
            sourceType: $this->sourceType,
            sourceId: $this->sourceId,
            clientId: $source['client_id'],
            sourceText: $source['text'],
            availableTags: $availableTags,
        );
    }

    private function automaticSuggestionsEnabled(): bool
    {
        return Organization::query()
            ->whereKey($this->organizationId)
            ->whereHas('settings', fn ($query) => $query
                ->where('setting_key', OrganizationSettingKey::AnalyticsAiAutomaticTagSuggestionsEnabled->value)
                ->where('boolean_value', true))
            ->exists();
    }

    /** @return array{taxonomy: string, client_id: int|null, text: string}|null */
    private function source(MedicalProfilePainReader $profilePains, MedicalSessionPainReader $sessionPains): ?array
    {
        if ($this->sourceType === 'medical_profile_pain') {
            $pain = MedicalProfilePain::query()
                ->where('organization_id', $this->organizationId)
                ->whereKey($this->sourceId)
                ->with('profile')
                ->first();
            if ($pain === null || $pain->profile === null) {
                return null;
            }
            $data = collect($profilePains->forProfile($pain->profile))->firstWhere('id', (int) $pain->getKey());

            return $data === null ? null : [
                'taxonomy' => 'complaints',
                'client_id' => (int) $pain->client_id,
                'text' => $data->name,
            ];
        }

        if ($this->sourceType === 'medical_session_pain') {
            $pain = MedicalSessionPain::query()
                ->where('organization_id', $this->organizationId)
                ->whereKey($this->sourceId)
                ->with('session')
                ->first();
            if ($pain === null || $pain->session === null) {
                return null;
            }
            $data = collect($sessionPains->forSession($pain->session))->firstWhere('id', (int) $pain->getKey());

            return $data === null ? null : [
                'taxonomy' => 'complaints',
                'client_id' => (int) $pain->client_id,
                'text' => $data->name,
            ];
        }

        if ($this->sourceType === 'feedback_submission') {
            $submission = FeedbackSubmission::query()
                ->where('organization_id', $this->organizationId)
                ->whereKey($this->sourceId)
                ->first();

            return $submission === null ? null : [
                'taxonomy' => 'feedback',
                'client_id' => (int) $submission->client_id,
                'text' => (string) ($submission->internal_feedback ?? ''),
            ];
        }

        if ($this->sourceType === 'tracker_task') {
            $task = TrackerTask::query()
                ->where('organization_id', $this->organizationId)
                ->whereKey($this->sourceId)
                ->first();

            return $task === null ? null : [
                'taxonomy' => 'goals',
                'client_id' => (int) $task->client_id,
                'text' => (string) $task->title,
            ];
        }

        return null;
    }

    /** @param array<int|string, string> $options
     * @return list<array{id: int, label: string}>
     */
    private function options(array $options): array
    {
        return array_map(
            static fn (int|string $id, string $label): array => ['id' => (int) $id, 'label' => $label],
            array_keys($options),
            array_values($options),
        );
    }
}
