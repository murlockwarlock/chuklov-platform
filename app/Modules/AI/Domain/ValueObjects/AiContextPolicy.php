<?php

namespace App\Modules\AI\Domain\ValueObjects;

use App\Modules\AI\Domain\Services\AiRuntimeLimits;
use InvalidArgumentException;

final readonly class AiContextPolicy
{
    /**
     * @param  list<int>  $ragKnowledgeSourceIds
     * @param  list<string>  $allowedContextTypes
     */
    public function __construct(
        public bool $includeClientProfile = false,
        public bool $includeMedicalSummary = false,
        public int $includeRecentSessionsCount = 0,
        public bool $includeRag = false,
        public array $ragKnowledgeSourceIds = [],
        public int $ragMaxChunks = 3,
        public float $ragMinSimilarity = 0.65,
        public array $allowedContextTypes = [],
        public bool $requireGroundedRag = false,
        public bool $allowRagDegradation = false,
    ) {
        if ($this->ragMaxChunks < 1 || $this->ragMaxChunks > AiRuntimeLimits::PLATFORM_MAX_RAG_CHUNKS) {
            throw new InvalidArgumentException('RAG chunk limit is outside the platform safety bounds.');
        }

        if ($this->includeRecentSessionsCount < 0 || $this->includeRecentSessionsCount > AiRuntimeLimits::PLATFORM_MAX_CONTEXT_SESSIONS) {
            throw new InvalidArgumentException('Recent session context limit is outside the platform safety bounds.');
        }

        if ($this->ragMinSimilarity < 0.0 || $this->ragMinSimilarity > 1.0) {
            throw new InvalidArgumentException('RAG similarity threshold must be between zero and one.');
        }

        $knownTypes = ['client_profile', 'medical_summary', 'recent_sessions', 'rag'];
        if (array_diff($this->allowedContextTypes, $knownTypes) !== []) {
            throw new InvalidArgumentException('Context policy contains an unsupported context type.');
        }
    }

    public function allows(string $contextType): bool
    {
        return $this->allowedContextTypes === [] || in_array($contextType, $this->allowedContextTypes, true);
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            includeClientProfile: (bool) ($data['include_client_profile'] ?? false),
            includeMedicalSummary: (bool) ($data['include_medical_summary'] ?? false),
            includeRecentSessionsCount: (int) ($data['include_recent_sessions_count'] ?? 0),
            includeRag: (bool) ($data['include_rag'] ?? false),
            ragKnowledgeSourceIds: array_values(array_map('intval', (array) ($data['rag_knowledge_source_ids'] ?? []))),
            ragMaxChunks: (int) ($data['rag_max_chunks'] ?? 3),
            ragMinSimilarity: (float) ($data['rag_min_similarity'] ?? 0.65),
            allowedContextTypes: array_values(array_map('strval', (array) ($data['allowed_context_types'] ?? []))),
            requireGroundedRag: (bool) ($data['require_grounded_rag'] ?? false),
            allowRagDegradation: (bool) ($data['allow_rag_degradation'] ?? false),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'include_client_profile' => $this->includeClientProfile,
            'include_medical_summary' => $this->includeMedicalSummary,
            'include_recent_sessions_count' => $this->includeRecentSessionsCount,
            'include_rag' => $this->includeRag,
            'rag_knowledge_source_ids' => $this->ragKnowledgeSourceIds,
            'rag_max_chunks' => $this->ragMaxChunks,
            'rag_min_similarity' => $this->ragMinSimilarity,
            'allowed_context_types' => $this->allowedContextTypes,
            'require_grounded_rag' => $this->requireGroundedRag,
            'allow_rag_degradation' => $this->allowRagDegradation,
        ];
    }
}
