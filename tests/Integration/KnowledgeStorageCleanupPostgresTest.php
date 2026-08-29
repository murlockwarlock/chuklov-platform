<?php

namespace Tests\Integration;

use App\Modules\Knowledge\Application\ProcessKnowledgeStorageCleanupOperation;
use App\Modules\Knowledge\Domain\Enums\KnowledgeStorageCleanupStatus;
use App\Modules\Knowledge\Domain\Models\KnowledgeStorageCleanupOperation;
use App\Modules\Organizations\Domain\Models\Organization;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Concurrency;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Throwable;

final class KnowledgeStorageCleanupPostgresTest extends TestCase
{
    use DatabaseTruncation;

    protected function tearDown(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            $this->truncateTablesForAllConnections();
        }

        parent::tearDown();
    }

    public function test_two_processes_claim_one_cleanup_operation_once(): void
    {
        $this->requirePostgres();
        $organization = Organization::factory()->create();
        $operation = KnowledgeStorageCleanupOperation::query()->create([
            'organization_id' => $organization->getKey(),
            'cleanup_key' => hash('sha256', 'postgres-cleanup-race'),
            'storage_disk' => 'private',
            'storage_path' => 'knowledge/cleanup-race/'.$organization->getKey().'.txt',
            'status' => KnowledgeStorageCleanupStatus::Pending,
            'available_at' => now(),
        ]);

        $results = Concurrency::driver('process')->run([
            static fn (): string => self::processInProcess($organization->getKey(), $operation->getKey()),
            static fn (): string => self::processInProcess($organization->getKey(), $operation->getKey()),
        ]);

        self::assertSame(['ok', 'ok'], $results);
        $operation->refresh();
        self::assertSame(KnowledgeStorageCleanupStatus::Succeeded, $operation->status);
        self::assertSame(1, $operation->attempts);
        self::assertNull($operation->processing_token);
    }

    public function test_cleanup_table_has_tenant_boundary_and_postgresql_safe_identifiers(): void
    {
        $this->requirePostgres();
        $relations = DB::select("SELECT relname FROM pg_class WHERE relname LIKE 'knowledge_cleanup%'");

        self::assertNotEmpty($relations);
        foreach ($relations as $relation) {
            self::assertLessThanOrEqual(63, strlen((string) $relation->relname));
        }

        self::assertTrue(DB::table('pg_constraint')->where('conname', 'knowledge_storage_cleanup_operations_organization_id_foreign')->exists());
        $statusConstraint = DB::table('pg_constraint')
            ->where('conname', 'knowledge_cleanup_status_check')
            ->value(DB::raw('pg_get_constraintdef(oid)'));
        self::assertIsString($statusConstraint);
        self::assertStringContainsString("'failed'", $statusConstraint);
        self::assertTrue(DB::table('pg_indexes')->where('indexname', 'knowledge_cleanup_org_key_unique')->exists());
        $indexes = DB::table('pg_indexes')
            ->whereIn('indexname', ['knowledge_cleanup_global_due_idx', 'knowledge_cleanup_global_stale_idx'])
            ->pluck('indexdef', 'indexname');
        self::assertStringContainsString('(status, available_at, id)', (string) $indexes->get('knowledge_cleanup_global_due_idx'));
        self::assertStringContainsString('(status, processing_started_at, id)', (string) $indexes->get('knowledge_cleanup_global_stale_idx'));
        self::assertTrue(DB::table('pg_indexes')->where('indexname', 'knowledge_revisions_storage_identity_idx')->exists());
    }

    private static function processInProcess(int $organizationId, int $operationId): string
    {
        try {
            app(ProcessKnowledgeStorageCleanupOperation::class)->handle($organizationId, $operationId);

            return 'ok';
        } catch (Throwable) {
            return 'error';
        }
    }

    private function requirePostgres(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('The Knowledge cleanup concurrency tests require PostgreSQL.');
        }
    }
}
