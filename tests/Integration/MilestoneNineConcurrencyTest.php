<?php

namespace Tests\Integration;

use App\Modules\Knowledge\Application\ClaimKnowledgeIngestionRun;
use App\Modules\Knowledge\Domain\Models\KnowledgeIngestionRun;
use App\Modules\Knowledge\Domain\Models\KnowledgeRevision;
use App\Modules\Knowledge\Domain\Models\KnowledgeSource;
use App\Modules\Knowledge\Domain\ValueObjects\ChunkingConfiguration;
use App\Modules\Knowledge\Domain\ValueObjects\EmbeddingConfiguration;
use App\Modules\Organizations\Domain\Models\Organization;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Concurrency;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class MilestoneNineConcurrencyTest extends TestCase
{
    use DatabaseTruncation;

    protected function tearDown(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            $this->truncateTablesForAllConnections();
        }

        parent::tearDown();
    }

    public function test_two_workers_claim_one_ingestion_run_once(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('The ingestion claim race requires PostgreSQL row locks.');
        }

        $organization = Organization::factory()->create();
        $source = KnowledgeSource::query()->create(['organization_id' => $organization->getKey(), 'type' => 'authored_text', 'title' => 'Guide', 'status' => 'active']);
        $revision = KnowledgeRevision::query()->create([
            'organization_id' => $organization->getKey(), 'knowledge_source_id' => $source->getKey(), 'version' => 1,
            'status' => 'pending', 'content' => 'booking guide', 'mime_type' => 'text/markdown', 'size_bytes' => 13,
            'content_checksum' => hash('sha256', 'booking guide'),
        ]);

        $results = Concurrency::driver('process')->run([
            static fn (): string => self::claim($organization->getKey(), $source->getKey(), $revision->getKey()),
            static fn (): string => self::claim($organization->getKey(), $source->getKey(), $revision->getKey()),
        ]);

        self::assertSame(1, count(array_filter($results, static fn (string $result): bool => $result === 'claimed')));
        self::assertSame(1, count(array_filter($results, static fn (string $result): bool => $result === 'busy')));
        self::assertSame(1, KnowledgeIngestionRun::query()->where('knowledge_revision_id', $revision->getKey())->count());
        self::assertSame(1, KnowledgeIngestionRun::query()->where('knowledge_revision_id', $revision->getKey())->value('attempts'));
    }

    private static function claim(int $organizationId, int $sourceId, int $revisionId): string
    {
        try {
            $run = app(ClaimKnowledgeIngestionRun::class)->handle(
                $organizationId,
                $sourceId,
                $revisionId,
                EmbeddingConfiguration::active(),
                ChunkingConfiguration::active(),
            );

            return $run instanceof KnowledgeIngestionRun ? 'claimed' : 'busy';
        } catch (\Throwable $exception) {
            return 'error:'.get_class($exception);
        }
    }
}
