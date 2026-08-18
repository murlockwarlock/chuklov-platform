<?php

namespace Tests\Integration;

use App\Models\User;
use App\Modules\Knowledge\Application\CreateKnowledgeSource;
use App\Modules\Knowledge\Application\Data\RetrievalQuery;
use App\Modules\Knowledge\Domain\Contracts\EmbeddingGenerator;
use App\Modules\Knowledge\Domain\Contracts\KnowledgeRetriever;
use App\Modules\Knowledge\Domain\ValueObjects\EmbeddingConfiguration;
use App\Modules\Knowledge\Domain\ValueObjects\EmbeddingExecutionSnapshot;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Models\Organization;
use Carbon\Carbon;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class PgvectorStatementTimeoutTest extends TestCase
{
    use DatabaseTruncation;

    public function test_bounded_pgvector_retrieval_applies_local_statement_timeout_to_metadata_and_vector_queries(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL statement timeout integration requires the pgvector database.');
        }

        config()->set('queue.default', 'sync');
        config()->set('rag.embedding.pricing', [
            'provider' => config('rag.embedding.provider'),
            'model' => config('rag.embedding.model'),
            'configuration_version' => config('rag.embedding.configuration_version'),
            'currency' => 'USD',
            'input_cost_per_million_minor_units' => 0,
            'zero_cost_local' => true,
        ]);
        $embedding = new class implements EmbeddingGenerator
        {
            public bool $advanceClock = false;

            public ?Carbon $startedAt = null;

            public function generate(array $inputs, EmbeddingConfiguration $configuration): array
            {
                if ($this->advanceClock && $this->startedAt !== null) {
                    Carbon::setTestNow($this->startedAt->copy()->addSeconds(5));
                }

                return array_map(
                    static fn (): array => array_fill(0, $configuration->dimensions, 0.0),
                    $inputs,
                );
            }
        };
        $this->app->instance(EmbeddingGenerator::class, $embedding);

        $organization = Organization::factory()->create();
        $actor = User::factory()->forOrganization($organization)->create();
        config()->set('tenancy.default_organization_id', $organization->getKey());
        app(OrganizationContext::class)->set($organization);
        $source = app(CreateKnowledgeSource::class)->handle($actor, [
            'title' => 'Statement timeout guide',
            'type' => 'authored_text',
            'content' => 'Booking retrieval timeout verification.',
        ]);

        $startedAt = Carbon::now();
        $boundedTimeouts = [];
        $restoredTimeouts = [];
        $expectingBoundedTimeout = true;
        $callerStatementTimeout = (string) DB::scalar("select current_setting('statement_timeout')");
        $metadataQueries = 0;
        DB::listen(static function (QueryExecuted $event) use (
            &$boundedTimeouts,
            &$restoredTimeouts,
            &$expectingBoundedTimeout,
            &$metadataQueries,
            $startedAt,
        ): void {
            $sql = strtolower($event->sql);
            if (str_contains($sql, "set_config('statement_timeout'")) {
                $timeout = (string) ($event->bindings[0] ?? '');
                if ($expectingBoundedTimeout) {
                    $boundedTimeouts[] = (int) $timeout;
                    $expectingBoundedTimeout = false;
                } else {
                    $restoredTimeouts[] = $timeout;
                    $expectingBoundedTimeout = true;
                }
            }

            if (str_contains($sql, 'knowledge_sources')
                && ! str_contains($sql, 'knowledge_chunks')
                && $metadataQueries < 3) {
                $metadataQueries++;
                Carbon::setTestNow($startedAt->copy()->addSeconds($metadataQueries));
            }
        });

        $embedding->startedAt = $startedAt;
        $embedding->advanceClock = true;
        Carbon::setTestNow($startedAt);

        try {
            app(KnowledgeRetriever::class)->retrieve(
                $actor,
                new RetrievalQuery(
                    text: 'booking',
                    topK: 5,
                    sourceIds: [(int) $source->getKey()],
                    executionDeadlineAt: $startedAt->copy()->addSeconds(30),
                    executionTimeoutSeconds: 30,
                    embeddingSnapshot: EmbeddingExecutionSnapshot::active(),
                ),
            );
        } finally {
            Carbon::setTestNow();
        }

        self::assertSame(3, $metadataQueries);
        self::assertGreaterThanOrEqual(4, count($boundedTimeouts));
        self::assertCount(count($boundedTimeouts), $restoredTimeouts);
        self::assertTrue($expectingBoundedTimeout);
        foreach ($boundedTimeouts as $timeoutMilliseconds) {
            self::assertGreaterThan(0, $timeoutMilliseconds);
            self::assertLessThanOrEqual(30_000, $timeoutMilliseconds);
        }
        foreach ($restoredTimeouts as $restoredTimeout) {
            self::assertSame($callerStatementTimeout, $restoredTimeout);
        }
        self::assertGreaterThan($boundedTimeouts[1], $boundedTimeouts[0]);
        self::assertGreaterThan($boundedTimeouts[2], $boundedTimeouts[1]);
        self::assertGreaterThan($boundedTimeouts[3], $boundedTimeouts[2]);
        self::assertLessThanOrEqual(25_000, $boundedTimeouts[array_key_last($boundedTimeouts)]);
    }

    public function test_bounded_pgvector_retrieval_restores_outer_transaction_statement_timeout(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL statement timeout integration requires the pgvector database.');
        }

        config()->set('queue.default', 'sync');
        config()->set('rag.embedding.pricing', [
            'provider' => config('rag.embedding.provider'),
            'model' => config('rag.embedding.model'),
            'configuration_version' => config('rag.embedding.configuration_version'),
            'currency' => 'USD',
            'input_cost_per_million_minor_units' => 0,
            'zero_cost_local' => true,
        ]);
        $embedding = new class implements EmbeddingGenerator
        {
            public function generate(array $inputs, EmbeddingConfiguration $configuration): array
            {
                return array_map(
                    static fn (): array => array_fill(0, $configuration->dimensions, 0.0),
                    $inputs,
                );
            }
        };
        $this->app->instance(EmbeddingGenerator::class, $embedding);

        $organization = Organization::factory()->create();
        $actor = User::factory()->forOrganization($organization)->create();
        config()->set('tenancy.default_organization_id', $organization->getKey());
        app(OrganizationContext::class)->set($organization);
        $source = app(CreateKnowledgeSource::class)->handle($actor, [
            'title' => 'Outer transaction statement timeout guide',
            'type' => 'authored_text',
            'content' => 'Outer transaction timeout restoration verification.',
        ]);

        DB::beginTransaction();

        try {
            DB::selectOne("select set_config('statement_timeout', ?, true) as statement_timeout", ['47s']);
            $callerStatementTimeout = (string) DB::scalar("select current_setting('statement_timeout')");

            app(KnowledgeRetriever::class)->retrieve(
                $actor,
                new RetrievalQuery(
                    text: 'outer transaction',
                    topK: 5,
                    sourceIds: [(int) $source->getKey()],
                    executionDeadlineAt: Carbon::now()->addSeconds(30),
                    executionTimeoutSeconds: 30,
                    embeddingSnapshot: EmbeddingExecutionSnapshot::active(),
                ),
            );

            self::assertSame(
                $callerStatementTimeout,
                (string) DB::scalar("select current_setting('statement_timeout')"),
            );
            $unrelatedQuery = DB::selectOne("select current_setting('statement_timeout') as statement_timeout, 1 as result");

            self::assertSame($callerStatementTimeout, (string) $unrelatedQuery->statement_timeout);
            self::assertSame(1, (int) $unrelatedQuery->result);
        } finally {
            DB::rollBack();
        }
    }
}
