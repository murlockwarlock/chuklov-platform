<?php

namespace App\Modules\AI\Application\Actions;

use App\Models\User;
use App\Modules\AI\Application\Data\AiRunProtectedTraceData;
use App\Modules\AI\Domain\Models\AiRun;
use App\Modules\AI\Domain\Models\AiRunPayload;
use App\Modules\MedicalProfiles\Domain\Contracts\MedicalEncryptorInterface;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use Illuminate\Auth\Access\AuthorizationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class GetAiRunProtectedTrace
{
    public function __construct(
        private readonly OrganizationContext $context,
        private readonly OrganizationAuthorizer $authorizer,
        private readonly MedicalEncryptorInterface $medicalEncryptor,
    ) {}

    public function handle(User $actor, int $runId): AiRunProtectedTraceData
    {
        $organization = $this->context->organization();

        if (! $this->authorizer->allows($actor, $organization, OrganizationPermission::ViewAiTrace)) {
            throw new AuthorizationException('User is not authorized to view AI protected traces.');
        }

        $run = AiRun::query()
            ->where('organization_id', $organization->getKey())
            ->where('id', $runId)
            ->first();

        if ($run === null) {
            throw new NotFoundHttpException('AI run not found in current organization.');
        }

        $payload = AiRunPayload::query()
            ->where('organization_id', $organization->getKey())
            ->where('ai_run_id', $run->id)
            ->first();

        if ($payload === null) {
            return new AiRunProtectedTraceData(
                aiRunId: $run->id,
                encryptionKeyVersion: 1,
                systemPrompt: null,
                userPrompt: null,
                outputText: null,
                outputPayload: null,
                humanReviewNotes: null,
                humanEditedOutput: null,
            );
        }

        $orgId = (int) $organization->getKey();
        $keyVersion = $payload->encryption_key_version;

        $decryptedSystemPrompt = $payload->encrypted_system_prompt !== null
            ? $this->medicalEncryptor->decryptField($orgId, $payload->encrypted_system_prompt, $keyVersion)
            : null;

        $decryptedUserPrompt = $payload->encrypted_user_prompt !== null
            ? $this->medicalEncryptor->decryptField($orgId, $payload->encrypted_user_prompt, $keyVersion)
            : null;

        $decryptedOutputText = $payload->encrypted_output_text !== null
            ? $this->medicalEncryptor->decryptField($orgId, $payload->encrypted_output_text, $keyVersion)
            : null;

        $decryptedPayloadJson = $payload->encrypted_output_payload !== null
            ? $this->medicalEncryptor->decryptField($orgId, $payload->encrypted_output_payload, $keyVersion)
            : null;

        $decryptedNotes = $payload->encrypted_human_review_notes !== null
            ? $this->medicalEncryptor->decryptField($orgId, $payload->encrypted_human_review_notes, $keyVersion)
            : null;

        $decryptedEditedOutput = $payload->encrypted_human_edited_output !== null
            ? $this->medicalEncryptor->decryptField($orgId, $payload->encrypted_human_edited_output, $keyVersion)
            : null;

        $outputPayload = null;
        if ($decryptedPayloadJson !== null) {
            $decoded = json_decode($decryptedPayloadJson, true);
            if (is_array($decoded)) {
                $outputPayload = $decoded;
            }
        }

        return new AiRunProtectedTraceData(
            aiRunId: $run->id,
            encryptionKeyVersion: $keyVersion,
            systemPrompt: $decryptedSystemPrompt,
            userPrompt: $decryptedUserPrompt,
            outputText: $decryptedOutputText,
            outputPayload: $outputPayload,
            humanReviewNotes: $decryptedNotes,
            humanEditedOutput: $decryptedEditedOutput,
        );
    }
}
