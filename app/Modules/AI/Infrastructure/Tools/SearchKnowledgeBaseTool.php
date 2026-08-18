<?php

namespace App\Modules\AI\Infrastructure\Tools;

use App\Modules\AI\Domain\Contracts\AiToolInterface;
use App\Modules\AI\Domain\Exceptions\AiRagRetrievalException;
use App\Modules\AI\Domain\Services\AiRuntimeLimits;
use App\Modules\Knowledge\Application\Data\RetrievalQuery;
use App\Modules\Knowledge\Domain\Contracts\KnowledgeRetriever;
use App\Modules\Knowledge\Domain\ValueObjects\EmbeddingExecutionSnapshot;
use Carbon\CarbonInterface;
use Illuminate\Auth\Access\AuthorizationException;
use InvalidArgumentException;

class SearchKnowledgeBaseTool implements AiToolInterface
{
    public function __construct(
        private readonly KnowledgeRetriever $knowledgeRetriever,
    ) {}

    public function getName(): string
    {
        return 'search_knowledge_base';
    }

    public function getDescription(): string
    {
        return 'Поиск по верифицированной базе знаний организации.';
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'query' => [
                    'type' => 'string',
                    'description' => 'Поисковый запрос по материалам базы знаний.',
                    'maxLength' => AiRuntimeLimits::PLATFORM_MAX_RAG_QUERY_CHARACTERS,
                ],
                'max_results' => [
                    'type' => 'integer',
                    'description' => 'Максимальное количество фрагментов (1-10).',
                    'default' => 3,
                ],
                'knowledge_source_ids' => [
                    'type' => 'array',
                    'items' => ['type' => 'integer'],
                    'description' => 'Опциональный фильтр по ID источников знаний.',
                ],
            ],
            'required' => ['query'],
        ];
    }

    public function execute(
        int $organizationId,
        array $input,
        ?CarbonInterface $executionDeadlineAt = null,
        ?int $executionTimeoutSeconds = null,
        ?EmbeddingExecutionSnapshot $embeddingSnapshot = null,
    ): array {
        $query = trim((string) ($input['query'] ?? ''));
        if ($query === '') {
            return ['results' => [], 'count' => 0];
        }

        $limit = min(AiRuntimeLimits::PLATFORM_MAX_RAG_CHUNKS, max(1, (int) ($input['max_results'] ?? 3)));
        $sourceIds = ! empty($input['knowledge_source_ids'])
            ? array_values(array_map('intval', (array) $input['knowledge_source_ids']))
            : [];

        try {
            $retrievalQuery = new RetrievalQuery(
                text: $query,
                topK: $limit,
                sourceIds: $sourceIds,
                executionDeadlineAt: $executionDeadlineAt,
                executionTimeoutSeconds: $executionTimeoutSeconds,
                embeddingSnapshot: $embeddingSnapshot,
            );
            $results = $this->knowledgeRetriever->retrieveForOrganization($organizationId, $retrievalQuery);
        } catch (AuthorizationException $e) {
            throw new AiRagRetrievalException('Knowledge scope is not authorized.', reason: 'scope', previous: $e);
        } catch (InvalidArgumentException $e) {
            throw new AiRagRetrievalException('Knowledge retrieval configuration is invalid.', reason: 'configuration', previous: $e);
        } catch (AiRagRetrievalException $e) {
            throw $e;
        } catch (\RuntimeException $e) {
            $message = strtolower($e->getMessage());
            $reason = str_contains($message, 'incompatible')
                ? 'configuration'
                : (str_contains($message, 'deadline') || str_contains($message, 'timeout')
                    ? 'timeout'
                    : 'infrastructure');
            $message = $reason === 'configuration'
                ? 'Knowledge retrieval configuration is invalid.'
                : ($reason === 'timeout'
                    ? 'Knowledge retrieval exceeded its bounded execution time.'
                    : 'Knowledge retrieval infrastructure is unavailable.');

            throw new AiRagRetrievalException($message, reason: $reason, previous: $e);
        } catch (\Throwable $e) {
            throw new AiRagRetrievalException('Knowledge retrieval infrastructure is unavailable.', reason: 'infrastructure', previous: $e);
        }

        $formatted = [];
        foreach ($results as $result) {
            $formatted[] = [
                'chunk_id' => $result->chunkId,
                'source_id' => $result->sourceId,
                'source_title' => $result->sourceTitle,
                'source_type' => $result->sourceType,
                'revision_id' => $result->revisionId,
                'chunk_index' => $result->chunkIndex,
                'similarity' => round($result->similarity, 4),
                'content' => $result->content,
                'source_reference' => $result->sourceReference,
                'embedding_configuration_key' => $result->embeddingConfigurationKey,
            ];
        }

        return [
            'results' => $formatted,
            'count' => count($formatted),
        ];
    }
}
