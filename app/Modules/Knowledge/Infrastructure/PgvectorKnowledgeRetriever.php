<?php

namespace App\Modules\Knowledge\Infrastructure;

use App\Models\User;
use App\Modules\Knowledge\Application\Data\RetrievalQuery;
use App\Modules\Knowledge\Application\Data\RetrievalResult;
use App\Modules\Knowledge\Application\KnowledgeAuthorization;
use App\Modules\Knowledge\Domain\Contracts\EmbeddingGenerator;
use App\Modules\Knowledge\Domain\Contracts\KnowledgeRetriever;
use App\Modules\Knowledge\Domain\Enums\KnowledgeSourceType;
use App\Modules\Knowledge\Domain\Models\KnowledgeChunk;
use App\Modules\Knowledge\Domain\Models\KnowledgeSource;
use App\Modules\Knowledge\Domain\ValueObjects\EmbeddingConfiguration;
use App\Modules\Organizations\Application\OrganizationContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use RuntimeException;

final class PgvectorKnowledgeRetriever implements KnowledgeRetriever
{
    public function __construct(
        private readonly EmbeddingGenerator $embeddings,
        private readonly KnowledgeAuthorization $authorization,
        private readonly OrganizationContext $context,
    ) {}

    public function retrieve(User $actor, RetrievalQuery $query): array
    {
        $organization = $this->context->organization();
        $this->authorization->authorizeView($actor, $organization);
        $configuration = EmbeddingConfiguration::active();

        if ($query->sourceType !== null && ! KnowledgeSourceType::tryFrom($query->sourceType)) {
            throw new AuthorizationException('The requested knowledge scope is not available.');
        }

        if ($query->sourceIds !== []) {
            $count = KnowledgeSource::query()->where('organization_id', $organization->getKey())->whereIn('id', $query->sourceIds)->count();
            if ($count !== count(array_unique($query->sourceIds))) {
                throw new AuthorizationException('The requested knowledge source is not available.');
            }
        }

        $activeSources = $this->activeSourceQuery($organization->getKey(), $query);
        if (! (clone $activeSources)->exists()) {
            return [];
        }

        $hasIncompatibleSource = (clone $activeSources)
            ->whereNotExists(function (QueryBuilder $builder) use ($configuration): void {
                $builder->selectRaw('1')
                    ->from('knowledge_ingestion_runs')
                    ->whereColumn('knowledge_ingestion_runs.organization_id', 'knowledge_sources.organization_id')
                    ->whereColumn('knowledge_ingestion_runs.knowledge_revision_id', 'knowledge_sources.active_revision_id')
                    ->where('knowledge_ingestion_runs.status', 'ready')
                    ->where('knowledge_ingestion_runs.embedding_provider', $configuration->provider)
                    ->where('knowledge_ingestion_runs.embedding_model', $configuration->model)
                    ->where('knowledge_ingestion_runs.embedding_dimensions', $configuration->dimensions)
                    ->where('knowledge_ingestion_runs.embedding_configuration_version', $configuration->version);
            })
            ->exists();
        if ($hasIncompatibleSource) {
            throw new RuntimeException('Active embedding configuration is incompatible.');
        }

        $vector = $this->embeddings->generate([$query->text], $configuration)[0] ?? [];
        if (count($vector) !== $configuration->dimensions) {
            throw new RuntimeException('Active embedding configuration is incompatible.');
        }

        $base = KnowledgeChunk::query()
            ->join('knowledge_sources', function ($join): void {
                $join->on('knowledge_sources.id', '=', 'knowledge_chunks.knowledge_source_id')
                    ->on('knowledge_sources.organization_id', '=', 'knowledge_chunks.organization_id');
            })
            ->join('knowledge_revisions', function ($join): void {
                $join->on('knowledge_revisions.id', '=', 'knowledge_chunks.knowledge_revision_id')
                    ->on('knowledge_revisions.organization_id', '=', 'knowledge_chunks.organization_id');
            })
            ->join('knowledge_ingestion_runs', function ($join): void {
                $join->on('knowledge_ingestion_runs.id', '=', 'knowledge_chunks.knowledge_ingestion_run_id')
                    ->on('knowledge_ingestion_runs.organization_id', '=', 'knowledge_chunks.organization_id');
            })
            ->where('knowledge_chunks.organization_id', $organization->getKey())
            ->where('knowledge_sources.organization_id', $organization->getKey())
            ->where('knowledge_sources.status', 'active')
            ->whereColumn('knowledge_sources.active_revision_id', 'knowledge_chunks.knowledge_revision_id')
            ->where('knowledge_revisions.status', 'ready')
            ->where('knowledge_ingestion_runs.status', 'ready')
            ->where('knowledge_ingestion_runs.embedding_provider', $configuration->provider)
            ->where('knowledge_ingestion_runs.embedding_model', $configuration->model)
            ->where('knowledge_ingestion_runs.embedding_dimensions', $configuration->dimensions)
            ->where('knowledge_ingestion_runs.embedding_configuration_version', $configuration->version)
            ->when($query->sourceIds !== [], fn (Builder $builder): Builder => $builder->whereIn('knowledge_sources.id', $query->sourceIds))
            ->when($query->sourceType !== null, fn (Builder $builder): Builder => $builder->where('knowledge_sources.type', $query->sourceType))
            ->when($query->category !== null, fn (Builder $builder): Builder => $builder->where('knowledge_sources.category', $query->category));

        if (config('database.default') === 'pgsql') {
            return $this->retrieveWithPgvector($base, $vector, $query->topK, $configuration);
        }

        return $this->retrieveWithFallback($base, $vector, $query->topK, $configuration);
    }

    /** @return Builder<KnowledgeSource> */
    private function activeSourceQuery(int $organizationId, RetrievalQuery $query): Builder
    {
        return KnowledgeSource::query()
            ->join('knowledge_revisions', function ($join): void {
                $join->on('knowledge_revisions.id', '=', 'knowledge_sources.active_revision_id')
                    ->on('knowledge_revisions.organization_id', '=', 'knowledge_sources.organization_id')
                    ->on('knowledge_revisions.knowledge_source_id', '=', 'knowledge_sources.id');
            })
            ->where('knowledge_sources.organization_id', $organizationId)
            ->where('knowledge_sources.status', 'active')
            ->where('knowledge_revisions.status', 'ready')
            ->when($query->sourceIds !== [], fn (Builder $builder): Builder => $builder->whereIn('knowledge_sources.id', $query->sourceIds))
            ->when($query->sourceType !== null, fn (Builder $builder): Builder => $builder->where('knowledge_sources.type', $query->sourceType))
            ->when($query->category !== null, fn (Builder $builder): Builder => $builder->where('knowledge_sources.category', $query->category))
            ->select('knowledge_sources.id');
    }

    /**
     * @param  Builder<KnowledgeChunk>  $base
     * @param  list<float>  $vector
     * @return list<RetrievalResult>
     */
    private function retrieveWithPgvector(Builder $base, array $vector, int $topK, EmbeddingConfiguration $configuration): array
    {
        $rows = $base
            ->select([
                'knowledge_chunks.id as chunk_id', 'knowledge_chunks.knowledge_source_id as source_id',
                'knowledge_sources.title as source_title', 'knowledge_sources.type as source_type',
                'knowledge_chunks.knowledge_revision_id as revision_id', 'knowledge_revisions.version as revision_version',
                'knowledge_chunks.chunk_index', 'knowledge_chunks.content', 'knowledge_chunks.source_reference',
                'knowledge_chunks.start_offset', 'knowledge_chunks.end_offset', 'knowledge_chunks.knowledge_ingestion_run_id as ingestion_run_id',
                'knowledge_ingestion_runs.embedding_provider', 'knowledge_ingestion_runs.embedding_model',
                'knowledge_ingestion_runs.embedding_dimensions', 'knowledge_ingestion_runs.embedding_configuration_version',
            ])
            ->selectVectorDistance('knowledge_chunks.embedding', $vector, 'distance')
            ->orderByVectorDistance('knowledge_chunks.embedding', $vector)
            ->orderBy('knowledge_chunks.id')
            ->limit($topK)
            ->get();

        return array_values($rows->map(fn (KnowledgeChunk $row): RetrievalResult => $this->result($row, 1.0 - $row->distance, $configuration))->all());
    }

    /**
     * @param  Builder<KnowledgeChunk>  $base
     * @param  list<float>  $vector
     * @return list<RetrievalResult>
     */
    private function retrieveWithFallback(Builder $base, array $vector, int $topK, EmbeddingConfiguration $configuration): array
    {
        $rows = $base->addSelect([
            'knowledge_chunks.id as chunk_id', 'knowledge_chunks.knowledge_source_id as source_id',
            'knowledge_sources.title as source_title', 'knowledge_sources.type as source_type',
            'knowledge_chunks.knowledge_revision_id as revision_id', 'knowledge_revisions.version as revision_version',
            'knowledge_chunks.chunk_index', 'knowledge_chunks.content', 'knowledge_chunks.embedding', 'knowledge_chunks.source_reference',
            'knowledge_chunks.start_offset', 'knowledge_chunks.end_offset', 'knowledge_chunks.knowledge_ingestion_run_id as ingestion_run_id',
        ])->orderBy('knowledge_chunks.id')->get();

        $ranked = $rows->map(function (KnowledgeChunk $row) use ($vector, $configuration): array {
            $similarity = $this->cosine($vector, $this->vectorFromMixed($row->embedding));

            return ['result' => $this->result($row, $similarity, $configuration), 'similarity' => $similarity];
        })->sortByDesc(fn (array $item): float => $item['similarity'])->take($topK);

        $results = [];
        foreach ($ranked as $item) {
            $results[] = $item['result'];
        }

        return $results;
    }

    private function result(KnowledgeChunk $row, float $similarity, EmbeddingConfiguration $configuration): RetrievalResult
    {
        return new RetrievalResult(
            (int) $row->chunk_id, (int) $row->source_id, (string) $row->source_title, (string) $row->source_type,
            (int) $row->revision_id, (int) $row->revision_version, (int) $row->chunk_index, (string) $row->content,
            max(0.0, min(1.0, $similarity)), $row->source_reference !== null ? (string) $row->source_reference : null,
            (int) $row->start_offset, (int) $row->end_offset, (int) $row->ingestion_run_id, $configuration->key(),
        );
    }

    /**
     * @param  list<float>  $left
     * @param  list<float>  $right
     */
    private function cosine(array $left, array $right): float
    {
        if (count($left) === 0 || count($left) !== count($right)) {
            throw new RuntimeException('Stored embedding dimensions are incompatible.');
        }
        $dot = 0.0;
        $leftNorm = 0.0;
        $rightNorm = 0.0;
        foreach ($left as $index => $value) {
            $dot += $value * $right[$index];
            $leftNorm += $value * $value;
            $rightNorm += $right[$index] * $right[$index];
        }

        return $leftNorm === 0.0 || $rightNorm === 0.0 ? 0.0 : $dot / (sqrt($leftNorm) * sqrt($rightNorm));
    }

    /** @return list<float> */
    private function vectorFromMixed(mixed $value): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            throw new RuntimeException('Stored embedding is invalid.');
        }

        $vector = [];
        foreach ($value as $component) {
            if (! is_int($component) && ! is_float($component)) {
                throw new RuntimeException('Stored embedding is invalid.');
            }
            $vector[] = (float) $component;
        }

        return $vector;
    }
}
