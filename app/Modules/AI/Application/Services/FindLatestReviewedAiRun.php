<?php

namespace App\Modules\AI\Application\Services;

use App\Modules\AI\Domain\Enums\AiCapability;
use App\Modules\AI\Domain\Enums\AiRunStatus;
use App\Modules\AI\Domain\Enums\ClinicalSynthesizerWorkflow;
use App\Modules\AI\Domain\Enums\HumanReviewStatus;
use App\Modules\AI\Domain\Models\AiRun;
use App\Modules\Identity\Domain\Models\Client;

final readonly class FindLatestReviewedAiRun
{
    public function handle(Client $client, AiCapability $capability, int $organizationId, ?string $workflowKey = null): ?AiRun
    {
        if ((int) $client->organization_id !== $organizationId) {
            return null;
        }

        $query = AiRun::query()
            ->forOrganization($organizationId)
            ->where('client_id', $client->getKey())
            ->where('capability', $capability)
            ->where('status', AiRunStatus::Succeeded)
            ->whereIn('human_review_status', [
                HumanReviewStatus::Accepted,
                HumanReviewStatus::EditedAndAccepted,
            ])
            ->orderByDesc('finished_at')
            ->orderByDesc('id');

        if ($capability === AiCapability::ClinicalSynthesizer) {
            $query->where('workflow_key', $workflowKey ?? ClinicalSynthesizerWorkflow::Summary->value);
        } elseif ($workflowKey !== null) {
            $query->where('workflow_key', $workflowKey);
        }

        return $query->first();
    }
}
