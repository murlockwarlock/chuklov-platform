<?php

namespace App\Modules\AI\Infrastructure\Context;

use App\Models\User;
use App\Modules\AI\Application\Attachments\AiAttachmentResolver;
use App\Modules\AI\Application\Data\ContextAssemblyResult;
use App\Modules\AI\Domain\Contracts\AiContextAssemblerInterface;
use App\Modules\AI\Domain\Enums\AiCapability;
use App\Modules\AI\Domain\Exceptions\AiRagRetrievalException;
use App\Modules\AI\Domain\Services\AiRuntimeLimits;
use App\Modules\AI\Domain\ValueObjects\AiContextPolicy;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Knowledge\Application\Data\RetrievalQuery;
use App\Modules\Knowledge\Domain\Contracts\KnowledgeRetriever;
use App\Modules\Knowledge\Domain\Enums\KnowledgeAudience;
use App\Modules\Knowledge\Domain\ValueObjects\EmbeddingExecutionSnapshot;
use App\Modules\MedicalProfiles\Application\GetMedicalProfile;
use App\Modules\Sessions\Application\MedicalSessionAuthorization;
use App\Modules\Sessions\Domain\Models\MedicalSession;
use Carbon\CarbonInterface;
use Illuminate\Auth\Access\AuthorizationException;
use InvalidArgumentException;

class AiContextAssembler implements AiContextAssemblerInterface
{
    public function __construct(
        private readonly KnowledgeRetriever $knowledgeRetriever,
        private readonly ?GetMedicalProfile $getMedicalProfile = null,
        private readonly ?MedicalSessionAuthorization $sessionAuthorization = null,
        private readonly ?AiAttachmentResolver $attachmentResolver = null,
    ) {}

    public function assemble(
        int $organizationId,
        AiContextPolicy $policy,
        array $inputVariables,
        array $inputReferences,
        ?User $actor = null,
        ?CarbonInterface $executionDeadlineAt = null,
        ?EmbeddingExecutionSnapshot $embeddingSnapshot = null,
        ?AiCapability $capability = null,
    ): ContextAssemblyResult {
        $variables = $inputVariables;
        $ragChunks = [];
        $attachmentProvenance = [];
        $provenanceSummary = [
            'client_included' => false,
            'medical_summary_included' => false,
            'sessions_count' => 0,
            'rag_chunks_count' => 0,
            'rag_degraded' => false,
            'retrieval_embedding' => [
                'initial_query_count' => 0,
                'initial_query_characters' => 0,
                'estimated_cost_minor_units' => 0,
            ],
        ];

        $clientId = null;
        foreach ($inputReferences as $ref) {
            if ($ref->type === 'client') {
                $clientId = $ref->id;
                break;
            }
        }

        if ($capability === AiCapability::ClientCompanion
            && ($policy->includeMedicalSummary || $policy->includeRecentSessionsCount > 0)) {
            throw new InvalidArgumentException('Client Companion context cannot include protected medical records or session history.');
        }

        if (array_filter($inputReferences, static fn ($reference): bool => in_array($reference->type, ['medical_attachment', 'companion_attachment'], true)) !== []) {
            if ($capability === null) {
                throw new InvalidArgumentException('AI attachment context requires a capability.');
            }

            $resolver = $this->attachmentResolver ?? app(AiAttachmentResolver::class);
            $attachmentProvenance = $resolver->describe(
                organizationId: $organizationId,
                capability: $capability,
                references: $inputReferences,
                actor: $actor,
                clientId: $clientId,
            );
        }

        if ($clientId !== null && ($policy->includeClientProfile || $policy->includeMedicalSummary)) {
            if ($policy->includeClientProfile && ! $policy->allows('client_profile')) {
                throw new InvalidArgumentException('Client profile is not allowed by the context policy.');
            }

            if ($policy->includeMedicalSummary && ! $policy->allows('medical_summary')) {
                throw new InvalidArgumentException('Medical summary is not allowed by the context policy.');
            }

            $client = Client::query()
                ->where('organization_id', $organizationId)
                ->where('id', $clientId)
                ->first();

            if ($client !== null) {
                if ($policy->includeClientProfile) {
                    $variables['client_name'] = (string) ($client->full_name ?: 'Клиент');
                    if ($capability !== AiCapability::ClientCompanion) {
                        $variables['client_id'] = (string) $client->id;
                    }
                    $provenanceSummary['client_included'] = true;
                }

                if ($policy->includeMedicalSummary) {
                    if (! $actor instanceof User || $this->getMedicalProfile === null) {
                        throw new InvalidArgumentException('An explicit authorized actor is required for protected medical context.');
                    }

                    $profile = $this->getMedicalProfile->handle($actor, $client);
                    if ($profile !== null) {
                        $variables['anamnesis'] = $profile->anamnesis ?? '';
                        $variables['complaints_goals'] = $profile->complaintsGoals ?? '';
                        $provenanceSummary['medical_summary_included'] = true;
                    }
                }
            }
        }

        if ($clientId !== null && $policy->includeRecentSessionsCount > 0) {
            if (! $policy->allows('recent_sessions')) {
                throw new InvalidArgumentException('Recent session context is not allowed by the context policy.');
            }

            if (! $actor instanceof User) {
                throw new InvalidArgumentException('An explicit authorized actor is required for protected session context.');
            }

            $sessionAuthorization = $this->sessionAuthorization ?? app(MedicalSessionAuthorization::class);
            $client = Client::query()
                ->where('organization_id', $organizationId)
                ->whereKey($clientId)
                ->first()
                ?? throw new InvalidArgumentException('Client context was not found for recent session assembly.');

            $sessions = MedicalSession::query()
                ->where('organization_id', $organizationId)
                ->where('client_id', $clientId)
                ->orderBy('occurred_at', 'desc')
                ->limit($policy->includeRecentSessionsCount)
                ->get();

            $sessionsData = [];
            foreach ($sessions as $session) {
                $sessionAuthorization->authorizeView($actor, $session, $client);
                $sessionsData[] = [
                    'session_id' => $session->id,
                    'occurred_at' => $session->occurred_at->toDateString(),
                ];
            }
            $variables['recent_sessions'] = $sessionsData;
            $provenanceSummary['sessions_count'] = count($sessionsData);
        }

        if ($policy->includeRag) {
            if (! $policy->allows('rag')) {
                throw new InvalidArgumentException('RAG context is not allowed by the context policy.');
            }

            $query = AiRuntimeLimits::ragQuery($inputVariables);
            if ($query !== '') {
                try {
                    $executionTimeoutSeconds = null;
                    $embeddingCost = 0;
                    if ($executionDeadlineAt !== null) {
                        $executionTimeoutSeconds = min(
                            AiRuntimeLimits::PLATFORM_MAX_TOOL_EXECUTION_SECONDS,
                            AiRuntimeLimits::remainingExecutionSeconds($executionDeadlineAt),
                        );
                        if ($executionTimeoutSeconds < 1) {
                            throw new AiRagRetrievalException(
                                'AI execution deadline expired before knowledge retrieval.',
                                reason: 'timeout',
                            );
                        }

                        $embeddingSnapshot ??= EmbeddingExecutionSnapshot::active();
                        $embeddingSnapshot->assertCurrent();
                        $embeddingCost = $embeddingSnapshot->pricing->estimateCostForQuery($query);
                        $provenanceSummary['retrieval_embedding'] = [
                            'initial_query_count' => 1,
                            'initial_query_characters' => mb_strlen($query),
                            'estimated_cost_minor_units' => $embeddingCost,
                            'configuration_snapshot' => $embeddingSnapshot->configuration->toArray(),
                            'pricing_snapshot' => $embeddingSnapshot->pricing->toArray(),
                        ];
                    }

                    $retrievalQuery = new RetrievalQuery(
                        text: $query,
                        topK: min(20, max(1, $policy->ragMaxChunks)),
                        sourceIds: $policy->ragKnowledgeSourceIds,
                        executionDeadlineAt: $executionDeadlineAt,
                        executionTimeoutSeconds: $executionTimeoutSeconds,
                        embeddingSnapshot: $embeddingSnapshot,
                        audience: $capability === AiCapability::ClientCompanion ? KnowledgeAudience::ClientCompanion : null,
                    );
                    $results = $this->knowledgeRetriever->retrieveForOrganization($organizationId, $retrievalQuery);

                    $results = array_values(array_filter(
                        $results,
                        static fn ($result): bool => $result->similarity >= $policy->ragMinSimilarity,
                    ));

                    $ragChunks = $results;
                    $ragContexts = [];
                    foreach ($results as $result) {
                        $ragContexts[] = "[Источник: {$result->sourceTitle}] {$result->content}";
                    }

                    $ragContext = implode("\n\n", $ragContexts);
                    if (AiRuntimeLimits::upperBoundTokenCount($ragContext) > AiRuntimeLimits::PLATFORM_MAX_RAG_CONTEXT_TOKENS) {
                        throw new AiRagRetrievalException(
                            'RAG context exceeds the bounded context limit.',
                            reason: 'context_limit',
                        );
                    }

                    if ($results === [] && $policy->requireGroundedRag) {
                        throw new AiRagRetrievalException(
                            'Grounded RAG policy requires a qualifying knowledge result.',
                            reason: 'no_grounding',
                        );
                    }

                    $variables['rag_context'] = $ragContext;
                    $provenanceSummary['rag_chunks_count'] = count($results);
                } catch (AiRagRetrievalException $e) {
                    if ($policy->requireGroundedRag
                        || ! $policy->allowRagDegradation
                        || in_array($e->reason, ['scope', 'configuration', 'context_limit'], true)) {
                        throw $e;
                    }

                    $provenanceSummary['rag_degraded'] = true;
                    $variables['rag_context'] = '';
                } catch (AuthorizationException $e) {
                    throw new AiRagRetrievalException('Knowledge scope is not authorized.', reason: 'scope', previous: $e);
                } catch (InvalidArgumentException $e) {
                    throw new AiRagRetrievalException('Knowledge retrieval configuration is invalid.', reason: 'configuration', previous: $e);
                } catch (\Throwable $e) {
                    $ragFailure = new AiRagRetrievalException(
                        'Knowledge retrieval infrastructure is unavailable.',
                        reason: 'infrastructure',
                        previous: $e,
                    );

                    if ($policy->requireGroundedRag || ! $policy->allowRagDegradation) {
                        throw $ragFailure;
                    }

                    $provenanceSummary['rag_degraded'] = true;
                    $variables['rag_context'] = '';
                }
            } elseif ($policy->requireGroundedRag) {
                throw new AiRagRetrievalException(
                    'Grounded RAG policy requires a retrieval query.',
                    reason: 'missing_query',
                );
            }
        }

        return new ContextAssemblyResult(
            variables: $variables,
            ragChunks: $ragChunks,
            provenanceSummary: $provenanceSummary,
            attachmentProvenance: $attachmentProvenance,
        );
    }
}
