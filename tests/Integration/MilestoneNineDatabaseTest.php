<?php

namespace Tests\Integration;

use App\Models\User;
use App\Modules\Knowledge\Domain\Models\KnowledgeSource;
use App\Modules\Organizations\Domain\Models\Organization;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class MilestoneNineDatabaseTest extends TestCase
{
    use RefreshDatabase;

    public function test_pgvector_column_and_provenance_constraint_exist_without_approximate_index(): void
    {
        $column = DB::selectOne("select format_type(a.atttypid, a.atttypmod) as type from pg_attribute a where a.attrelid = 'knowledge_chunks'::regclass and a.attname = 'embedding'");
        self::assertSame('vector(1536)', $column->type);
        self::assertSame(0, DB::table('pg_indexes')->where('tablename', 'knowledge_chunks')->whereRaw("indexdef ilike '%vector_cosine_ops%'")->count());
        self::assertSame(1, DB::table('pg_constraint')->where('conname', 'knowledge_chunks_run_provenance_foreign')->count());
    }

    public function test_cross_organization_revision_reference_is_rejected_by_database(): void
    {
        $organizationA = Organization::factory()->create();
        $organizationB = Organization::factory()->create();
        $source = KnowledgeSource::query()->create(['organization_id' => $organizationA->getKey(), 'type' => 'authored_text', 'title' => 'A', 'status' => 'active']);
        $actor = User::factory()->forOrganization($organizationB)->create();

        $this->expectException(QueryException::class);
        DB::table('knowledge_revisions')->insert([
            'organization_id' => $organizationB->getKey(), 'knowledge_source_id' => $source->getKey(), 'version' => 1,
            'status' => 'pending', 'content' => 'cross org', 'mime_type' => 'text/markdown', 'size_bytes' => 9,
            'content_checksum' => hash('sha256', 'cross org'), 'created_by_user_id' => $actor->getKey(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_duplicate_ingestion_configuration_is_rejected_by_database(): void
    {
        $organization = Organization::factory()->create();
        $source = KnowledgeSource::query()->create(['organization_id' => $organization->getKey(), 'type' => 'authored_text', 'title' => 'A', 'status' => 'active']);
        $revisionId = DB::table('knowledge_revisions')->insertGetId([
            'organization_id' => $organization->getKey(), 'knowledge_source_id' => $source->getKey(), 'version' => 1,
            'status' => 'pending', 'content' => 'text', 'mime_type' => 'text/markdown', 'size_bytes' => 4,
            'content_checksum' => hash('sha256', 'text'), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $row = [
            'organization_id' => $organization->getKey(), 'knowledge_source_id' => $source->getKey(), 'knowledge_revision_id' => $revisionId,
            'configuration_key' => str_repeat('a', 64), 'status' => 'pending', 'chunk_strategy' => 'window', 'chunk_version' => 'v1',
            'chunk_target_characters' => 100, 'chunk_maximum_characters' => 120, 'chunk_overlap_characters' => 10,
            'embedding_provider' => 'fake', 'embedding_model' => 'fake', 'embedding_dimensions' => 1536,
            'embedding_configuration_version' => 'v1', 'attempts' => 0, 'created_at' => now(), 'updated_at' => now(),
        ];
        DB::table('knowledge_ingestion_runs')->insert($row);

        $this->expectException(QueryException::class);
        DB::table('knowledge_ingestion_runs')->insert($row);
    }

    public function test_ingestion_attempt_history_has_tenant_provenance_and_bounded_terminal_state(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('The attempt-history constraint inspection requires PostgreSQL.');
        }

        $columns = DB::table('information_schema.columns')
            ->where('table_name', 'knowledge_ingestion_attempts')
            ->pluck('column_name')
            ->all();
        self::assertContains('organization_id', $columns);
        self::assertContains('knowledge_source_id', $columns);
        self::assertContains('knowledge_revision_id', $columns);
        self::assertContains('knowledge_ingestion_run_id', $columns);
        self::assertContains('attempt_number', $columns);

        self::assertTrue(DB::table('pg_indexes')
            ->where('tablename', 'knowledge_ingestion_attempts')
            ->where('indexname', 'knowledge_ingestion_attempts_org_run_attempt_unique')
            ->exists());
        self::assertSame(4, DB::table('pg_constraint as constraints')
            ->join('pg_class as tables', 'tables.oid', '=', 'constraints.conrelid')
            ->where('tables.relname', 'knowledge_ingestion_attempts')
            ->where('constraints.contype', 'f')
            ->count());
        self::assertSame(1, DB::table('pg_constraint')->where('conname', 'knowledge_ingestion_attempts_status_check')->count());
        self::assertSame(1, DB::table('pg_constraint')->where('conname', 'knowledge_ingestion_attempts_number_check')->count());
    }
}
