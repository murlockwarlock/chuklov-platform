<?php

namespace App\Modules\AI\Infrastructure\Tools;

use App\Modules\AI\Domain\Contracts\AiToolInterface;
use App\Modules\AI\Domain\Exceptions\AiRagRetrievalException;
use App\Modules\Knowledge\Application\Data\RetrievalQuery;
use App\Modules\Knowledge\Domain\Contracts\KnowledgeRetriever;
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

    public function execute(int $organizationId, array $input): array
    {
        $query = trim((string) ($input['query'] ?? ''));
        if ($query === '') {
            return ['results' => [], 'count' => 0];
        }

        $limit = min(10, max(1, (int) ($input['max_results'] ?? 3)));
        $sourceIds = ! empty($input['knowledge_source_ids'])
            ? array_values(array_map('intval', (array) $input['knowledge_source_ids']))
            : [];

        try {
            $retrievalQuery = new RetrievalQuery(
                text: $query,
                topK: $limit,
                sourceIds: $sourceIds,
            );
            $results = $this->knowledgeRetriever->retrieveForOrganization($organizationId, $retrievalQuery);
        } catch (AuthorizationException $e) {
            throw new AiRagRetrievalException('Knowledge scope is not authorized.', reason: 'scope', previous: $e);
        } catch (InvalidArgumentException $e) {
            throw new AiRagRetrievalException('Knowledge retrieval configuration is invalid.', reason: 'configuration', previous: $e);
        } catch (AiRagRetrievalException $e) {
            throw $e;
        } catch (\RuntimeException $e) {
            $reason = str_contains(strtolower($e->getMessage()), 'incompatible')
                ? 'configuration'
                : 'infrastructure';
            $message = $reason === 'configuration'
                ? 'Knowledge retrieval configuration is invalid.'
                : 'Knowledge retrieval infrastructure is unavailable.';

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
