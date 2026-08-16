<?php

namespace App\Modules\AI\Infrastructure\Context;

use App\Models\User;
use App\Modules\AI\Application\Data\ContextAssemblyResult;
use App\Modules\AI\Domain\Contracts\AiContextAssemblerInterface;
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
    ): ContextAssemblyResult {
        $variables = $inputVariables;
        $ragChunks = [];
        $provenanceSummary = [
            'client_included' => false,
            'medical_summary_included' => false,
            'sessions_count' => 0,
            'rag_chunks_count' => 0,
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

                $actor = Auth::user();
                if ($policy->includeMedicalSummary && $this->getMedicalProfile !== null && $actor instanceof User) {
                    try {
                        $profile = $this->getMedicalProfile->handle($actor, $client);
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
                $actor = Auth::user();
                if ($actor instanceof User) {
                    try {
                        $retrievalQuery = new RetrievalQuery(
                            text: $query,
                            topK: min(20, max(1, $policy->ragMaxChunks)),
                            sourceIds: $policy->ragKnowledgeSourceIds,
                        );
                        $results = $this->knowledgeRetriever->retrieve($actor, $retrievalQuery);

                        $ragChunks = $results;
                        $ragContexts = [];
                        foreach ($results as $result) {
                            $ragContexts[] = "[Источник: {$result->sourceTitle}] {$result->sourceReference}";
                        }
                        $variables['rag_context'] = implode("\n\n", $ragContexts);
                        $provenanceSummary['rag_chunks_count'] = count($results);
                    } catch (\Throwable) {
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
