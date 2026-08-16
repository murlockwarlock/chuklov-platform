<?php

namespace App\Modules\Surveys\Application;

use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Surveys\Domain\Models\SurveyAttempt;

final readonly class GetClientSurveyAttempt
{
    public function __construct(private SurveyAuthorization $authorization) {}

    public function handle(Client $client, int $attemptId): SurveyAttempt
    {
        $attempt = SurveyAttempt::query()
            ->where('organization_id', $client->organization_id)
            ->where('client_id', $client->getKey())
            ->findOrFail($attemptId);
        $this->authorization->assertAttemptOwner($client, $attempt);

        return $attempt;
    }
}
