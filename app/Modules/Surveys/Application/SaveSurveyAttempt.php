<?php

namespace App\Modules\Surveys\Application;

use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Surveys\Domain\Enums\SurveyAttemptStatus;
use App\Modules\Surveys\Domain\Models\SurveyAttempt;
use App\Modules\Surveys\Domain\Services\SurveyScorer;
use Illuminate\Support\Facades\DB;

final readonly class SaveSurveyAttempt
{
    public function __construct(
        private SurveyAuthorization $authorization,
        private SurveyScorer $scorer,
    ) {}

    /** @param array<string, mixed> $answers */
    public function handle(Client $client, SurveyAttempt $attempt, array $answers): SurveyAttempt
    {
        $this->authorization->assertAttemptOwner($client, $attempt);

        return DB::transaction(function () use ($client, $attempt, $answers): SurveyAttempt {
            $locked = SurveyAttempt::query()->where('organization_id', $client->organization_id)->whereKey($attempt->getKey())->lockForUpdate()->firstOrFail();
            abort_unless($locked->status === SurveyAttemptStatus::InProgress, 409, 'Завершённый тест нельзя изменить.');
            $locked->forceFill(['answers_snapshot' => $this->scorer->validateDraft($locked->definition_snapshot, $answers)])->save();

            return $locked->refresh();
        });
    }
}
