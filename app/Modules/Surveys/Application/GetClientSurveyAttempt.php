<?php

namespace App\Modules\Surveys\Application;

use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Surveys\Domain\Exceptions\SurveyAttemptUnavailableException;
use App\Modules\Surveys\Domain\Models\SurveyAttempt;
use Illuminate\Support\Facades\Log;

final readonly class GetClientSurveyAttempt
{
    public function __construct(private SurveyAuthorization $authorization) {}

    public function handle(Client $client, int $attemptId): SurveyAttempt
    {
        $attempt = SurveyAttempt::query()
            ->where('organization_id', $client->organization_id)
            ->where('client_id', $client->getKey())
            ->with('surveyVersion:id,organization_id,approval_status')
            ->find($attemptId);
        if ($attempt === null || $attempt->surveyVersion->approval_status !== 'approved') {
            Log::warning('portal.surveys.attempt_access_denied', [
                'operation' => 'view_survey_attempt',
                'organization_id' => $client->organization_id,
                'actor_type' => 'client',
                'actor_client_id' => $client->getKey(),
                'entity_type' => 'survey_attempt',
                'entity_id' => $attemptId,
                'safe_error_code' => 'attempt_unavailable',
                'outcome' => 'denied',
                'occurred_at_utc' => now()->utc()->toIso8601String(),
            ]);

            throw new SurveyAttemptUnavailableException;
        }
        $this->authorization->assertAttemptOwner($client, $attempt);

        return $attempt;
    }
}
