<?php

namespace App\Modules\AI\Application\Actions;

use App\Models\User;
use App\Modules\AI\Application\Data\ClinicalAiResultData;
use App\Modules\AI\Domain\Enums\AiCapability;
use App\Modules\AI\Domain\Enums\AiRunStatus;
use App\Modules\AI\Domain\Models\AiRun;
use App\Modules\AI\Domain\Models\AiRunPayload;
use App\Modules\MedicalProfiles\Domain\Contracts\MedicalEncryptorInterface;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use Illuminate\Auth\Access\AuthorizationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class GetClinicalAiResult
{
    public function __construct(
        private readonly OrganizationContext $context,
        private readonly OrganizationAuthorizer $authorizer,
        private readonly MedicalEncryptorInterface $medicalEncryptor,
    ) {}

    public function handle(User $actor, int $runId, ?int $clientId = null): ClinicalAiResultData
    {
        $organization = $this->context->organization();
        $this->authorizer->authorize($actor, $organization, OrganizationPermission::ViewClients);
        $this->authorizer->authorize($actor, $organization, OrganizationPermission::ViewAiRuns);

        $run = AiRun::query()
            ->where('organization_id', $organization->getKey())
            ->whereKey($runId)
            ->whereIn('capability', [
                AiCapability::ClinicalDocumentExtraction,
                AiCapability::PostureAnalysis,
                AiCapability::ClinicalSynthesizer,
            ])
            ->first();

        if ($run === null || ($clientId !== null && (int) $run->client_id !== $clientId)) {
            throw new NotFoundHttpException('Clinical AI result was not found in the current client context.');
        }

        if ($run->status !== AiRunStatus::Succeeded) {
            throw new AuthorizationException('Clinical AI result is not available before the run succeeds.');
        }

        $payload = AiRunPayload::query()
            ->where('organization_id', $organization->getKey())
            ->where('ai_run_id', $run->getKey())
            ->first();

        if ($payload === null) {
            return new ClinicalAiResultData(
                run: $run,
                outputPayload: null,
                outputText: null,
                attachmentProvenance: $this->attachmentProvenance($run),
            );
        }

        $orgId = (int) $organization->getKey();
        $outputText = $payload->encrypted_output_text === null
            ? null
            : $this->medicalEncryptor->decryptField($orgId, $payload->encrypted_output_text, $payload->encryption_key_version);
        $outputPayload = null;
        if ($payload->encrypted_output_payload !== null) {
            $decoded = json_decode(
                (string) $this->medicalEncryptor->decryptField(
                    $orgId,
                    $payload->encrypted_output_payload,
                    $payload->encryption_key_version,
                ),
                true,
            );
            $outputPayload = is_array($decoded) ? $decoded : null;
        }

        return new ClinicalAiResultData(
            run: $run,
            outputPayload: $outputPayload,
            outputText: $outputText,
            attachmentProvenance: $this->attachmentProvenance($run),
        );
    }

    /** @return list<array<string, mixed>> */
    private function attachmentProvenance(AiRun $run): array
    {
        $provenance = $run->context_provenance['attachments'] ?? [];

        return is_array($provenance) ? array_values(array_filter($provenance, 'is_array')) : [];
    }
}
