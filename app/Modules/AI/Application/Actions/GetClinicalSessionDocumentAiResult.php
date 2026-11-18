<?php

namespace App\Modules\AI\Application\Actions;

use App\Models\User;
use App\Modules\AI\Application\Data\ClinicalAiResultData;
use App\Modules\AI\Application\Services\ListClinicalSessionDocumentAiRuns;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Sessions\Domain\Models\MedicalSession;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final readonly class GetClinicalSessionDocumentAiResult
{
    public function __construct(
        private ListClinicalSessionDocumentAiRuns $runs,
        private GetClinicalAiResult $result,
    ) {}

    public function handle(
        User $actor,
        Client $client,
        MedicalSession $session,
        int $runId,
    ): ClinicalAiResultData {
        $run = $this->runs
            ->handle($actor, $session, $client)
            ->first(fn ($candidate): bool => (int) $candidate->getKey() === $runId);

        if ($run === null) {
            throw new NotFoundHttpException('Clinical document result was not found in the current session context.');
        }

        return $this->result->handle($actor, $runId, $client->getKey());
    }
}
