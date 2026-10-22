<?php

namespace App\Modules\Surveys\Application;

use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Surveys\Domain\Enums\SurveyAttemptStatus;
use App\Modules\Surveys\Domain\Enums\SurveyVersionStatus;
use App\Modules\Surveys\Domain\Exceptions\SurveyAttemptUnavailableException;
use App\Modules\Surveys\Domain\Models\SurveyAttempt;
use App\Modules\Surveys\Domain\Models\SurveyDefinition;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final readonly class StartSurveyAttempt
{
    public function __construct(private SurveyAuthorization $authorization) {}

    public function handle(Client $client, SurveyDefinition $definition): SurveyAttempt
    {
        $this->authorization->assertClient($client);
        $this->authorization->assertDefinition($definition);

        return DB::transaction(function () use ($client, $definition): SurveyAttempt {
            $lockedDefinition = SurveyDefinition::query()
                ->where('organization_id', $client->organization_id)
                ->whereKey($definition->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            $version = $lockedDefinition->activeVersion()->where('organization_id', $client->organization_id)->first();
            if (! $lockedDefinition->is_available) {
                $this->rejectUnavailable($client, $lockedDefinition, $version?->getKey(), 'definition_unavailable');
            }
            if ($version === null) {
                $this->rejectUnavailable($client, $lockedDefinition, null, 'active_version_missing');
            }
            if ($version->status !== SurveyVersionStatus::Published) {
                $this->rejectUnavailable($client, $lockedDefinition, (int) $version->getKey(), 'version_unpublished');
            }
            if ($version->approval_status !== 'approved') {
                $this->rejectUnavailable($client, $lockedDefinition, (int) $version->getKey(), 'content_not_approved');
            }

            $existing = SurveyAttempt::query()
                ->where('organization_id', $client->organization_id)
                ->where('client_id', $client->getKey())
                ->where('survey_version_id', $version->getKey())
                ->where('status', SurveyAttemptStatus::InProgress)
                ->latest('id')
                ->first();
            if ($existing !== null) {
                return $existing;
            }

            return SurveyAttempt::query()->create([
                'organization_id' => $client->organization_id,
                'client_id' => $client->getKey(),
                'survey_definition_id' => $lockedDefinition->getKey(),
                'survey_version_id' => $version->getKey(),
                'status' => SurveyAttemptStatus::InProgress,
                'definition_snapshot' => $version->definition,
                'answers_snapshot' => [],
                'scoring_snapshot' => $version->scoring,
                'metric_schema_key' => $version->metric_schema_key,
                'started_at' => now(),
            ]);
        });
    }

    private function rejectUnavailable(Client $client, SurveyDefinition $definition, ?int $versionId, string $errorCode): never
    {
        Log::notice('portal.surveys.start_denied', [
            'operation' => 'start_survey_attempt',
            'organization_id' => $client->organization_id,
            'actor_type' => 'client',
            'actor_client_id' => $client->getKey(),
            'entity_type' => 'survey_definition',
            'entity_id' => $definition->getKey(),
            'survey_version_id' => $versionId,
            'safe_error_code' => $errorCode,
            'outcome' => 'denied',
            'occurred_at_utc' => now()->utc()->toIso8601String(),
        ]);

        throw new SurveyAttemptUnavailableException;
    }
}
