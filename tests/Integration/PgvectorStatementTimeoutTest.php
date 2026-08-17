<?php

namespace Tests\Integration;

use App\Models\User;
use App\Modules\Knowledge\Application\CreateKnowledgeSource;
use App\Modules\Knowledge\Application\Data\RetrievalQuery;
use App\Modules\Knowledge\Domain\Contracts\EmbeddingGenerator;
use App\Modules\Knowledge\Domain\Contracts\KnowledgeRetriever;
use App\Modules\Knowledge\Domain\ValueObjects\EmbeddingConfiguration;
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
        app(CreateKnowledgeSource::class)->handle($actor, [
            'title' => 'Statement timeout guide',
            'type' => 'authored_text',
            'content' => 'Booking retrieval timeout verification.',
        ]);

        $timeouts = [];
        DB::listen(static function (QueryExecuted $event) use (&$timeouts): void {
            if (str_contains(strtolower($event->sql), "set_config('statement_timeout'")) {
                $timeouts[] = (int) ($event->bindings[0] ?? 0);
            }
        });

        $startedAt = Carbon::now();
        $embedding->startedAt = $startedAt;
        $embedding->advanceClock = true;

        try {
            app(KnowledgeRetriever::class)->retrieve(
                $actor,
                new RetrievalQuery(
                    text: 'booking',
                    topK: 5,
                    executionDeadlineAt: $startedAt->copy()->addSeconds(30),
                    executionTimeoutSeconds: 30,
                ),
            );
        } finally {
            Carbon::setTestNow();
        }

        self::assertGreaterThanOrEqual(2, count($timeouts));
        foreach ($timeouts as $timeoutMilliseconds) {
            self::assertGreaterThan(0, $timeoutMilliseconds);
            self::assertLessThanOrEqual(30_000, $timeoutMilliseconds);
        }
        self::assertLessThan($timeouts[0], $timeouts[array_key_last($timeouts)]);
        self::assertLessThanOrEqual(25_000, $timeouts[array_key_last($timeouts)]);
    }
}
