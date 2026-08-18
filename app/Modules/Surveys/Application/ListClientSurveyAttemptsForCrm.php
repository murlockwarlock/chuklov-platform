<?php

namespace App\Modules\Surveys\Application;

use App\Models\User;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Surveys\Domain\Models\SurveyAttempt;
use Illuminate\Database\Eloquent\Builder;

final readonly class ListClientSurveyAttemptsForCrm
{
    public function __construct(private SurveyAuthorization $authorization) {}

    /** @return Builder<SurveyAttempt> */
    public function query(User $actor, Client $client): Builder
    {
        return $this->apply($actor, $client, SurveyAttempt::query());
    }

    /**
     * @param  Builder<SurveyAttempt>  $query
     * @return Builder<SurveyAttempt>
     */
    public function apply(User $actor, Client $client, Builder $query): Builder
    {
        $organization = $this->authorization->view($actor);
        $this->authorization->assertClient($client);

        return $query
            ->where('organization_id', $organization->getKey())
            ->where('client_id', $client->getKey())
            ->select([
                'id',
                'organization_id',
                'client_id',
                'survey_definition_id',
                'survey_version_id',
                'status',
                'started_at',
                'completed_at',
            ])
            ->with([
                'surveyDefinition:id,organization_id,title',
                'surveyVersion:id,organization_id,survey_definition_id,version,title',
                'report:id,organization_id,survey_attempt_id,title,materialized_at',
            ])
            ->orderByDesc('started_at')
            ->orderByDesc('id');
    }
}
