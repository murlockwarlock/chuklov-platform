<?php

namespace App\Modules\Knowledge\Application\Data;

use App\Modules\Knowledge\Domain\ValueObjects\EmbeddingConfiguration;
use Carbon\CarbonInterface;
use InvalidArgumentException;

final readonly class RetrievalQuery
{
    public const int MAX_TEXT_LENGTH = EmbeddingConfiguration::MAX_QUERY_CHARACTERS;

    public const int MAX_EXECUTION_TIMEOUT_SECONDS = EmbeddingConfiguration::MAX_RUNTIME_TIMEOUT_SECONDS;

    /** @var list<int> */
    public array $sourceIds;

    /** @param array<array-key, mixed> $sourceIds */
    public function __construct(
        public string $text,
        public int $topK = 5,
        array $sourceIds = [],
        public ?string $sourceType = null,
        public ?string $category = null,
        public ?CarbonInterface $executionDeadlineAt = null,
        public ?int $executionTimeoutSeconds = null,
    ) {
        $maximumTopK = (int) config('rag.retrieval.maximum_top_k', 20);

        $sourceIdsAreValid = array_is_list($sourceIds) && count(array_filter($sourceIds, static fn (mixed $id): bool => is_int($id) && $id > 0)) === count($sourceIds);
        if (trim($text) === ''
            || mb_strlen($text) > self::MAX_TEXT_LENGTH
            || $topK < 1
            || $topK > $maximumTopK
            || count($sourceIds) > 20
            || ! $sourceIdsAreValid
            || ($category !== null && mb_strlen($category) > 80)
            || ($executionTimeoutSeconds !== null && ($executionTimeoutSeconds < 1 || $executionTimeoutSeconds > self::MAX_EXECUTION_TIMEOUT_SECONDS))) {
            throw new InvalidArgumentException('Retrieval query is invalid.');
        }

        $this->sourceIds = $sourceIds;
    }
}
