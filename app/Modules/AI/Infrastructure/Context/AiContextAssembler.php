<?php

namespace App\Modules\AI\Infrastructure\Context;

use App\Models\User;
use App\Modules\AI\Application\Data\ContextAssemblyResult;
use App\Modules\AI\Domain\Contracts\AiContextAssemblerInterface;
use App\Modules\AI\Domain\Exceptions\AiRagRetrievalException;
use App\Modules\AI\Domain\ValueObjects\AiContextPolicy;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Knowledge\Application\Data\RetrievalQuery;
use App\Modules\Knowledge\Domain\Contracts\KnowledgeRetriever;
use App\Modules\MedicalProfiles\Application\GetMedicalProfile;
use App\Modules\Sessions\Domain\Models\MedicalSession;
use Illuminate\Support\Facades\Auth;

class AiContextAssembler implements AiContextAssemblerInterface
{
    public function __construct(
        private readonly KnowledgeRetriever $knowledgeRetriever,
        private readonly ?GetMedicalProfile $getMedicalProfile = null,
    ) {}

    public function assemble(
        int $organizationId,
        AiContextPolicy $policy,
        array $inputVariables,
        array $inputReferences,
        ?User $actor = null,
    ): ContextAssemblyResult {
        $variables = $inputVariables;
        $ragChunks = [];
        $provenanceSummary = [
            'client_included' => false,
            'medical_summary_included' => false,
            'sessions_count' => 0,
            'rag_chunks_count' => 0,
            'rag_degraded' => false,
        ];

        $clientId = null;
        foreach ($inputReferences as $ref) {
            if ($ref->type === 'client') {
                $clientId = $ref->id;
                break;
            }
        }

        if ($clientId !== null && ($policy->includeClientProfile || $policy->includeMedicalSummary)) {
            $client = Client::query()
                ->where('organization_id', $organizationId)
                ->where('id', $clientId)
                ->first();

            if ($client !== null) {
                if ($policy->includeClientProfile) {
                    $variables['client_name'] = $client->name ?? 'Клиент';
                    $variables['client_id'] = (string) $client->id;
                    $provenanceSummary['client_included'] = true;
                }

                $effectiveActor = $actor ?? Auth::user();
                if ($policy->includeMedicalSummary && $this->getMedicalProfile !== null && $effectiveActor instanceof User) {
                    try {
                        $profile = $this->getMedicalProfile->handle($effectiveActor, $client);
                        if ($profile !== null) {
                            $variables['anamnesis'] = $profile->anamnesis ?? '';
                            $variables['complaints_goals'] = $profile->complaintsGoals ?? '';
                            $provenanceSummary['medical_summary_included'] = true;
                        }
                    } catch (\Throwable) {
                    }
                }
            }
        }

        if ($clientId !== null && $policy->includeRecentSessionsCount > 0) {
            $sessions = MedicalSession::query()
                ->where('organization_id', $organizationId)
                ->where('client_id', $clientId)
                ->orderBy('occurred_at', 'desc')
                ->limit($policy->includeRecentSessionsCount)
                ->get();

            $sessionsData = [];
            foreach ($sessions as $session) {
                $sessionsData[] = [
                    'session_id' => $session->id,
                    'occurred_at' => $session->occurred_at->toDateString(),
                ];
            }
            $variables['recent_sessions'] = $sessionsData;
            $provenanceSummary['sessions_count'] = count($sessionsData);
        }

        if ($policy->includeRag) {
            $query = (string) ($inputVariables['query'] ?? $inputVariables['question'] ?? $inputVariables['complaint'] ?? '');
            if ($query !== '') {
                try {
                    $retrievalQuery = new RetrievalQuery(
                        text: $query,
                        topK: min(20, max(1, $policy->ragMaxChunks)),
                        sourceIds: $policy->ragKnowledgeSourceIds,
                    );
                    $results = $this->knowledgeRetriever->retrieveForOrganization($organizationId, $retrievalQuery);

                    $ragChunks = $results;
                    $ragContexts = [];
                    foreach ($results as $result) {
                        $ragContexts[] = "[Источник: {$result->sourceTitle}] {$result->content}";
                    }
                    $variables['rag_context'] = implode("\n\n", $ragContexts);
                    $provenanceSummary['rag_chunks_count'] = count($results);
                } catch (\Throwable $e) {
                    if ($policy->requireGroundedRag) {
                        throw new AiRagRetrievalException('Grounding RAG knowledge retrieval failed.', previous: $e);
                    }
                    if ($policy->allowRagDegradation) {
                        $provenanceSummary['rag_degraded'] = true;
                        $variables['rag_context'] = '';
                    } else {
                        throw new AiRagRetrievalException('RAG retrieval failed and degradation is not permitted by policy.', previous: $e);
                    }
                }
            }
        }

        return new ContextAssemblyResult(
            variables: $variables,
            ragChunks: $ragChunks,
            provenanceSummary: $provenanceSummary,
        );
    }
}
