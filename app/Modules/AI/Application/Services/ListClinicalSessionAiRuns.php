<?php

namespace App\Modules\AI\Application\Services;

use App\Models\User;
use App\Modules\AI\Domain\Enums\AiCapability;
use App\Modules\AI\Domain\Enums\ClinicalSynthesizerWorkflow;
use App\Modules\AI\Domain\Models\AiRun;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use App\Modules\Sessions\Application\MedicalSessionAuthorization;
use App\Modules\Sessions\Domain\Models\MedicalSession;
use Illuminate\Database\Eloquent\Collection;

final readonly class ListClinicalSessionAiRuns
{
    public function __construct(
        private OrganizationContext $context,
        private OrganizationAuthorizer $authorizer,
        private MedicalSessionAuthorization $sessionAuthorization,
    ) {}

    /** @return Collection<int, AiRun> */
    public function handle(User $actor, MedicalSession $session, Client $client): Collection
    {
        $organization = $this->context->organization();
        $this->authorizer->authorize($actor, $organization, OrganizationPermission::ViewClients);
        $this->authorizer->authorize($actor, $organization, OrganizationPermission::ViewAiRuns);
        $this->sessionAuthorization->authorizeView($actor, $session, $client);

        $runs = AiRun::query()
            ->where('organization_id', $organization->getKey())
            ->where('client_id', $client->getKey())
            ->where('capability', AiCapability::ClinicalSynthesizer)
            ->where('workflow_key', ClinicalSynthesizerWorkflow::SessionDraft->value)
            ->with(['humanReviews.reviewer', 'promptVersion', 'modelRelease'])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(100)
            ->get();

        return $runs
            ->filter(fn (AiRun $run): bool => collect((array) $run->input_references)
                ->contains(fn (mixed $reference): bool => is_array($reference)
                    && ($reference['type'] ?? null) === 'medical_session'
                    && (int) ($reference['id'] ?? 0) === (int) $session->getKey()))
            ->values();
    }
}
