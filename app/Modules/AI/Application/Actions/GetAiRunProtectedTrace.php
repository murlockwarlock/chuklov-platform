<?php

namespace App\Modules\AI\Application\Actions;

use App\Models\User;
use App\Modules\AI\Application\Data\AiRunProtectedTraceData;
use App\Modules\AI\Domain\Enums\AiModelModality;
use App\Modules\AI\Domain\Models\AiRun;
use App\Modules\AI\Domain\Models\AiRunPayload;
use App\Modules\MedicalProfiles\Domain\Contracts\MedicalEncryptorInterface;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use Illuminate\Auth\Access\AuthorizationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class GetAiRunProtectedTrace
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
            ->with([
                'promptVersion.prompt',
                'modelRelease.modelConfiguration.providerConfiguration',
                'ragReferences.source',
            ])
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
                promptName: $run->promptVersion?->prompt?->name,
                promptVersion: $run->promptVersion?->version,
                inputReferences: $this->inputReferences($run),
                contextProvenance: $this->contextProvenance($run),
                ragReferences: $this->ragReferences($run),
                model: $this->model($run),
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

        [$sourcePrompt, $platformSafetyGuardrails] = $this->splitPrompt($decryptedSystemPrompt);

        return new AiRunProtectedTraceData(
            aiRunId: $run->id,
            encryptionKeyVersion: $keyVersion,
            systemPrompt: $decryptedSystemPrompt,
            userPrompt: $decryptedUserPrompt,
            outputText: $decryptedOutputText,
            outputPayload: $outputPayload,
            humanReviewNotes: $decryptedNotes,
            humanEditedOutput: $decryptedEditedOutput,
            promptName: $run->promptVersion?->prompt?->name,
            promptVersion: $run->promptVersion?->version,
            inputReferences: $this->inputReferences($run),
            contextProvenance: $this->contextProvenance($run),
            ragReferences: $this->ragReferences($run),
            model: $this->model($run),
            sourcePrompt: $sourcePrompt,
            platformSafetyGuardrails: $platformSafetyGuardrails,
        );
    }

    /** @return list<array<string, mixed>> */
    private function inputReferences(AiRun $run): array
    {
        return array_values(array_filter(
            (array) $run->input_references,
            static fn (mixed $reference): bool => is_array($reference),
        ));
    }

    /** @return array<string, mixed> */
    private function contextProvenance(AiRun $run): array
    {
        return is_array($run->context_provenance) ? $run->context_provenance : [];
    }

    /** @return list<array<string, mixed>> */
    private function ragReferences(AiRun $run): array
    {
        return $run->ragReferences
            ->map(static fn ($reference): array => [
                'index' => $reference->reference_index,
                'source_id' => $reference->knowledge_source_id,
                'source_title' => $reference->source?->title,
                'revision_id' => $reference->knowledge_revision_id,
                'chunk_id' => $reference->knowledge_chunk_id,
                'similarity' => $reference->similarity_score,
                'retrieval_type' => $reference->retrieval_type,
            ])
            ->values()
            ->all();
    }

    /** @return array<string, mixed> */
    private function model(AiRun $run): array
    {
        $release = $run->modelRelease;
        $modelConfiguration = $release?->modelConfiguration;

        return [
            'provider' => $run->actual_provider ?? $run->requested_provider ?? $release?->provider_name,
            'model' => $run->actual_model ?? $run->requested_model ?? $release?->model_name,
            'release_id' => $release?->getKey() ?? $run->model_release_id,
            'release_number' => $release?->release_number,
            'modalities' => collect($release?->capabilities ?? $modelConfiguration?->capabilities ?? [])
                ->filter(static fn (mixed $value): bool => in_array($value, array_map(static fn (AiModelModality $modality): string => $modality->value, AiModelModality::cases()), true))
                ->values()
                ->all(),
        ];
    }

    /** @return array{0: ?string, 1: ?string} */
    private function splitPrompt(?string $prompt): array
    {
        if ($prompt === null || trim($prompt) === '') {
            return [null, null];
        }

        $source = $prompt;
        $guardrails = [];
        $platformMarker = "\n\n[PLATFORM SAFETY GUARDRAIL]\n";
        if (str_contains($source, $platformMarker)) {
            [$source, $platformGuardrails] = explode($platformMarker, $source, 2);
            $guardrails[] = trim($platformGuardrails);
        }

        $systemMarker = "\n\n[SYSTEM-OWNED SAFETY POLICY]\n";
        if (str_contains($source, $systemMarker)) {
            [$source, $systemGuardrails] = explode($systemMarker, $source, 2);
            $guardrails[] = trim($systemGuardrails);
        }

        return [trim($source), $guardrails === [] ? null : implode("\n\n", array_filter($guardrails))];
    }
}
