<?php

namespace App\Modules\Surveys\Application;

use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Surveys\Domain\Enums\SurveyAttemptStatus;
use App\Modules\Surveys\Domain\Enums\SurveyVersionStatus;
use App\Modules\Surveys\Domain\Models\SurveyAttempt;
use App\Modules\Surveys\Domain\Models\SurveyDefinition;
use Illuminate\Support\Facades\DB;

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
            $version = $lockedDefinition->activeVersion()->where('organization_id', $client->organization_id)->firstOrFail();
            abort_unless($lockedDefinition->is_available && $version->status === SurveyVersionStatus::Published, 404);

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
}
