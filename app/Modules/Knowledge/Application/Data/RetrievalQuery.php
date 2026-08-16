<?php

namespace App\Modules\Knowledge\Application\Data;

use InvalidArgumentException;

final readonly class RetrievalQuery
{
    /** @var list<int> */
    public array $sourceIds;

    /** @param array<array-key, mixed> $sourceIds */
    public function __construct(
        public string $text,
        public int $topK = 5,
        array $sourceIds = [],
        public ?string $sourceType = null,
        public ?string $category = null,
    ) {
        $maximumTopK = (int) config('rag.retrieval.maximum_top_k', 20);

        $sourceIdsAreValid = array_is_list($sourceIds) && count(array_filter($sourceIds, static fn (mixed $id): bool => is_int($id) && $id > 0)) === count($sourceIds);
        if (trim($text) === '' || mb_strlen($text) > 4000 || $topK < 1 || $topK > $maximumTopK || count($sourceIds) > 20 || ! $sourceIdsAreValid || ($category !== null && mb_strlen($category) > 80)) {
            throw new InvalidArgumentException('Retrieval query is invalid.');
        }

        $this->sourceIds = $sourceIds;
    }
}
