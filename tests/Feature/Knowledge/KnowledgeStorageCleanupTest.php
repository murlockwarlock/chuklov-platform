<?php

namespace Tests\Feature\Knowledge;

use App\Models\User;
use App\Modules\AI\Domain\Enums\AiCapability;
use App\Modules\AI\Domain\Models\AiRun;
use App\Modules\AI\Domain\Models\AiRunRagReference;
use App\Modules\Knowledge\Application\CreateKnowledgeSource;
use App\Modules\Knowledge\Application\DeleteKnowledgeSource;
use App\Modules\Knowledge\Application\ProcessKnowledgeStorageCleanupOperation;
use App\Modules\Knowledge\Application\ScheduleKnowledgeStorageCleanup;
use App\Modules\Knowledge\Application\UpdateKnowledgeSource;
use App\Modules\Knowledge\Domain\Enums\KnowledgeStorageCleanupStatus;
use App\Modules\Knowledge\Domain\Models\KnowledgeChunk;
use App\Modules\Knowledge\Domain\Models\KnowledgeIngestionRun;
use App\Modules\Knowledge\Domain\Models\KnowledgeRevision;
use App\Modules\Knowledge\Domain\Models\KnowledgeSource;
use App\Modules\Knowledge\Domain\Models\KnowledgeStorageCleanupOperation;
use App\Modules\Knowledge\Domain\ValueObjects\ChunkingConfiguration;
use App\Modules\Knowledge\Domain\ValueObjects\EmbeddingConfiguration;
use App\Modules\Knowledge\Jobs\ProcessKnowledgeStorageCleanup;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Models\Organization;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

final class KnowledgeStorageCleanupTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Storage::fake('private');
        config()->set('rag.cleanup.retry_after_seconds', 1);
        config()->set('rag.cleanup.max_attempts', 3);
    }

    public function test_unreferenced_uploaded_revision_deletion_creates_and_processes_a_durable_cleanup(): void
    {
        [$organization, $actor] = $this->fixture();
        $source = $this->uploadedSource($actor, 'delete-me.txt', 'delete me');
        $revision = $source->revisions()->sole();
        $revisionPath = $this->revisionPath($revision);

        app(DeleteKnowledgeSource::class)->handle($actor, $source);

        self::assertDatabaseMissing('knowledge_sources', ['id' => $source->getKey()]);
        self::assertDatabaseMissing('knowledge_revisions', ['id' => $revision->getKey()]);
        $operation = KnowledgeStorageCleanupOperation::query()->where('organization_id', $organization->getKey())->firstOrFail();
        Queue::assertPushed(ProcessKnowledgeStorageCleanup::class, fn (ProcessKnowledgeStorageCleanup $job): bool => $job->organizationId === $organization->getKey()
            && $job->operationId === $operation->getKey());
        Storage::disk('private')->assertExists($revisionPath);

        app(ProcessKnowledgeStorageCleanupOperation::class)->handle($organization->getKey(), $operation->getKey());

        self::assertSame(KnowledgeStorageCleanupStatus::Succeeded, $operation->refresh()->status);
        Storage::disk('private')->assertMissing($revisionPath);
    }

    public function test_rag_referenced_revision_and_object_are_retained_while_source_is_retired(): void
    {
        [$organization, $actor] = $this->fixture();
        $source = $this->uploadedSource($actor, 'retained.txt', 'retained');
        $revision = $source->revisions()->sole();
        $revisionPath = $this->revisionPath($revision);
        $this->addRagReference($organization, $source, $revision);

        app(DeleteKnowledgeSource::class)->handle($actor, $source);

        self::assertDatabaseHas('knowledge_sources', ['id' => $source->getKey(), 'status' => 'retired', 'active_revision_id' => null]);
        self::assertDatabaseHas('knowledge_revisions', ['id' => $revision->getKey()]);
        self::assertSame(0, KnowledgeStorageCleanupOperation::query()->where('organization_id', $organization->getKey())->count());
        Storage::disk('private')->assertExists($revisionPath);
    }

    public function test_mixed_deletion_only_removes_unreferenced_revision_object(): void
    {
        [$organization, $actor] = $this->fixture();
        $source = $this->uploadedSource($actor, 'first.txt', 'first');
        $firstRevision = $source->revisions()->sole();
        $secondRevision = $this->uploadedSourceReplacement($actor, $source, 'second.txt', 'second');
        $firstPath = $this->revisionPath($firstRevision);
        $secondPath = $this->revisionPath($secondRevision);
        $this->addRagReference($organization, $source, $secondRevision);

        app(DeleteKnowledgeSource::class)->handle($actor, $source);
        $operation = KnowledgeStorageCleanupOperation::query()->where('organization_id', $organization->getKey())->firstOrFail();
        app(ProcessKnowledgeStorageCleanupOperation::class)->handle($organization->getKey(), $operation->getKey());

        self::assertDatabaseMissing('knowledge_revisions', ['id' => $firstRevision->getKey()]);
        self::assertDatabaseHas('knowledge_revisions', ['id' => $secondRevision->getKey()]);
        Storage::disk('private')->assertMissing($firstPath);
        Storage::disk('private')->assertExists($secondPath);
    }

    public function test_shared_object_referenced_by_another_remaining_revision_is_protected(): void
    {
        [$organization, $actor] = $this->fixture();
        $source = $this->uploadedSource($actor, 'first.txt', 'first');
        $otherSource = $this->uploadedSource($actor, 'other.txt', 'other');
        $revision = $source->revisions()->sole();
        $otherRevision = $otherSource->revisions()->sole();
        $revisionPath = $this->revisionPath($revision);
        DB::table('knowledge_revisions')->where('id', $otherRevision->getKey())->update(['storage_path' => $revisionPath]);

        app(DeleteKnowledgeSource::class)->handle($actor, $source);
        $operation = KnowledgeStorageCleanupOperation::query()->where('organization_id', $organization->getKey())->firstOrFail();
        app(ProcessKnowledgeStorageCleanupOperation::class)->handle($organization->getKey(), $operation->getKey());

        $operation->refresh();
        self::assertSame(KnowledgeStorageCleanupStatus::Protected, $operation->status);
        self::assertSame('storage_object_referenced', $operation->error_code);
        self::assertDatabaseHas('knowledge_revisions', ['id' => $otherRevision->getKey(), 'storage_path' => $revisionPath]);
        Storage::disk('private')->assertExists($revisionPath);
    }

    public function test_cleanup_failure_is_typed_retryable_and_a_later_attempt_is_idempotent(): void
    {
        [$organization] = $this->fixture();
        $path = 'knowledge/sources/'.$organization->getKey().'/retry.txt';
        $operation = $this->operation($organization, $path);
        $disk = \Mockery::mock(FilesystemAdapter::class);
        $disk->shouldReceive('delete')->once()->with($path)->andReturnFalse();
        Storage::shouldReceive('disk')->once()->with('private')->andReturn($disk);

        app(ProcessKnowledgeStorageCleanupOperation::class)->handle($organization->getKey(), $operation->getKey());

        $operation->refresh();
        self::assertSame(KnowledgeStorageCleanupStatus::Retryable, $operation->status);
        self::assertSame('storage_delete_failed', $operation->error_code);
        self::assertSame(1, $operation->attempts);
        $operation->update(['available_at' => now()->subSecond()]);
        $disk->shouldReceive('delete')->once()->with($path)->andReturnTrue();
        Storage::shouldReceive('disk')->once()->with('private')->andReturn($disk);

        app(ProcessKnowledgeStorageCleanupOperation::class)->handle($organization->getKey(), $operation->getKey());
        app(ProcessKnowledgeStorageCleanupOperation::class)->handle($organization->getKey(), $operation->getKey());

        $operation->refresh();
        self::assertSame(KnowledgeStorageCleanupStatus::Succeeded, $operation->status);
        self::assertSame(2, $operation->attempts);
    }

    public function test_false_storage_delete_reaches_a_terminal_failed_state_at_the_attempt_limit(): void
    {
        [$organization] = $this->fixture();
        $path = 'knowledge/sources/'.$organization->getKey().'/permanent-failure.txt';
        $operation = $this->operation($organization, $path);
        $disk = \Mockery::mock(FilesystemAdapter::class);
        $disk->shouldReceive('delete')->times(3)->with($path)->andReturnFalse();
        Storage::shouldReceive('disk')->times(3)->with('private')->andReturn($disk);

        for ($attempt = 1; $attempt <= 3; $attempt++) {
            app(ProcessKnowledgeStorageCleanupOperation::class)->handle($organization->getKey(), $operation->getKey());
            $operation->refresh();

            if ($attempt < 3) {
                self::assertSame(KnowledgeStorageCleanupStatus::Retryable, $operation->status);
                $operation->update(['available_at' => now()->subMinutes(10)]);
            }
        }

        self::assertSame(KnowledgeStorageCleanupStatus::Failed, $operation->status);
        self::assertSame('storage_delete_failed', $operation->error_code);
        self::assertSame(3, $operation->attempts);
        self::assertNotNull($operation->processed_at);
        self::assertNull($operation->processing_token);
        app(ProcessKnowledgeStorageCleanupOperation::class)->handle($organization->getKey(), $operation->getKey());
        self::assertSame(3, $operation->refresh()->attempts);
        self::assertSame(0, app(ScheduleKnowledgeStorageCleanup::class)->handle());
        Queue::assertNothingPushed();
    }

    public function test_thrown_storage_failure_is_retryable_without_persisting_exception_text(): void
    {
        [$organization] = $this->fixture();
        $operation = $this->operation($organization, 'knowledge/sources/'.$organization->getKey().'/exception.txt');
        $disk = \Mockery::mock(FilesystemAdapter::class);
        $disk->shouldReceive('delete')->once()->andThrow(new RuntimeException('document contents must not persist'));
        Storage::shouldReceive('disk')->once()->with('private')->andReturn($disk);

        app(ProcessKnowledgeStorageCleanupOperation::class)->handle($organization->getKey(), $operation->getKey());

        $operation->refresh();
        self::assertSame(KnowledgeStorageCleanupStatus::Retryable, $operation->status);
        self::assertSame('storage_delete_exception', $operation->error_code);
        self::assertStringNotContainsString('document contents must not persist', (string) $operation->error_code);
    }

    public function test_storage_exception_reaches_a_terminal_failed_state_at_the_attempt_limit(): void
    {
        [$organization] = $this->fixture();
        $path = 'knowledge/sources/'.$organization->getKey().'/permanent-exception.txt';
        $operation = $this->operation($organization, $path);
        $disk = \Mockery::mock(FilesystemAdapter::class);
        $disk->shouldReceive('delete')->times(3)->with($path)->andThrow(new RuntimeException('secret backend details'));
        Storage::shouldReceive('disk')->times(3)->with('private')->andReturn($disk);

        for ($attempt = 1; $attempt <= 3; $attempt++) {
            app(ProcessKnowledgeStorageCleanupOperation::class)->handle($organization->getKey(), $operation->getKey());
            $operation->refresh();

            if ($attempt < 3) {
                self::assertSame(KnowledgeStorageCleanupStatus::Retryable, $operation->status);
                $operation->update(['available_at' => now()->subSecond()]);
            }
        }

        self::assertSame(KnowledgeStorageCleanupStatus::Failed, $operation->status);
        self::assertSame('storage_delete_exception', $operation->error_code);
        self::assertSame(3, $operation->attempts);
        self::assertNotNull($operation->processed_at);
        self::assertNull($operation->processing_token);
        self::assertStringNotContainsString('secret backend details', (string) $operation->error_code);
    }

    #[DataProvider('cleanupMaxAttemptsConfiguration')]
    public function test_cleanup_max_attempts_configuration_is_clamped(int $configured, int $expected): void
    {
        [$organization] = $this->fixture();
        config()->set('rag.cleanup.max_attempts', $configured);
        $path = 'knowledge/sources/'.$organization->getKey().'/clamped-'.$configured.'.txt';
        $operation = $this->operation($organization, $path);
        $disk = \Mockery::mock(FilesystemAdapter::class);
        $disk->shouldReceive('delete')->times($expected)->with($path)->andReturnFalse();
        Storage::shouldReceive('disk')->times($expected)->with('private')->andReturn($disk);

        for ($attempt = 1; $attempt <= $expected; $attempt++) {
            app(ProcessKnowledgeStorageCleanupOperation::class)->handle($organization->getKey(), $operation->getKey());
            self::assertSame($attempt, $operation->refresh()->attempts);
            if ($attempt < $expected) {
                $operation->update(['available_at' => now()->subMinutes(10)]);
            }
        }

        $operation->refresh();
        self::assertSame($expected, $operation->attempts);
        self::assertSame(KnowledgeStorageCleanupStatus::Failed, $operation->status);
    }

    public static function cleanupMaxAttemptsConfiguration(): array
    {
        return [
            'zero' => [0, 1],
            'negative' => [-5, 1],
            'excessive' => [1000, 10],
        ];
    }

    public function test_stale_processing_operation_at_attempt_limit_is_failed_without_another_attempt(): void
    {
        [$organization] = $this->fixture();
        config()->set('rag.cleanup.max_attempts', 1);
        $operation = $this->operation($organization, 'knowledge/sources/'.$organization->getKey().'/stale-at-limit.txt', KnowledgeStorageCleanupStatus::Processing);
        $operation->forceFill([
            'attempts' => 1,
            'processing_started_at' => now()->subHours(2),
            'processing_token' => str_repeat('a', 64),
            'error_code' => 'storage_delete_exception',
        ])->save();
        Storage::shouldReceive('disk')->never();

        app(ProcessKnowledgeStorageCleanupOperation::class)->handle($organization->getKey(), $operation->getKey());

        $operation->refresh();
        self::assertSame(KnowledgeStorageCleanupStatus::Failed, $operation->status);
        self::assertSame('storage_delete_exception', $operation->error_code);
        self::assertSame(1, $operation->attempts);
        self::assertNotNull($operation->processed_at);
        self::assertNull($operation->processing_token);
    }

    public function test_retryable_operation_at_attempt_limit_fails_closed_without_waiting_for_available_at(): void
    {
        [$organization] = $this->fixture();
        config()->set('rag.cleanup.max_attempts', 1);
        $operation = $this->operation($organization, 'knowledge/sources/'.$organization->getKey().'/retryable-at-limit.txt', KnowledgeStorageCleanupStatus::Retryable);
        $operation->forceFill([
            'attempts' => 1,
            'available_at' => now()->addHour(),
            'error_code' => 'storage_delete_failed',
        ])->save();
        Storage::shouldReceive('disk')->never();

        app(ProcessKnowledgeStorageCleanupOperation::class)->handle($organization->getKey(), $operation->getKey());

        $operation->refresh();
        self::assertSame(KnowledgeStorageCleanupStatus::Failed, $operation->status);
        self::assertSame('storage_delete_failed', $operation->error_code);
        self::assertSame(1, $operation->attempts);
        self::assertNotNull($operation->processed_at);
    }

    public function test_stale_cleanup_rechecks_references_before_physical_deletion(): void
    {
        [$organization, $actor] = $this->fixture();
        $source = $this->uploadedSource($actor, 'stale.txt', 'stale');
        $revision = $source->revisions()->sole();
        $revisionPath = $this->revisionPath($revision);
        $operation = $this->operation($organization, $revisionPath, KnowledgeStorageCleanupStatus::Processing);
        $operation->forceFill([
            'processing_started_at' => now()->subHours(2),
            'processing_token' => str_repeat('a', 64),
        ])->save();
        $this->addRagReference($organization, $source, $revision);
        Storage::disk('private')->assertExists($revisionPath);
        Storage::shouldReceive('disk')->never();

        app(ProcessKnowledgeStorageCleanupOperation::class)->handle($organization->getKey(), $operation->getKey());

        self::assertSame(KnowledgeStorageCleanupStatus::Protected, $operation->refresh()->status);
    }

    public function test_rollback_leaves_logical_deletion_and_cleanup_obligation_absent(): void
    {
        [$organization, $actor] = $this->fixture();
        $source = $this->uploadedSource($actor, 'rollback.txt', 'rollback');
        $revision = $source->revisions()->sole();
        $revisionPath = $this->revisionPath($revision);
        $throw = true;
        DB::listen(function (QueryExecuted $query) use (&$throw): void {
            if ($throw && str_contains($query->sql, 'knowledge_storage_cleanup_operations')) {
                $throw = false;
                throw new RuntimeException('cleanup insert failed');
            }
        });

        $this->expectException(RuntimeException::class);
        try {
            app(DeleteKnowledgeSource::class)->handle($actor, $source);
        } finally {
            self::assertDatabaseHas('knowledge_sources', ['id' => $source->getKey()]);
            self::assertDatabaseHas('knowledge_revisions', ['id' => $revision->getKey()]);
            self::assertSame(0, KnowledgeStorageCleanupOperation::query()->where('organization_id', $organization->getKey())->count());
            Storage::disk('private')->assertExists($revisionPath);
        }
    }

    public function test_operation_identifier_is_organization_scoped(): void
    {
        [$organization] = $this->fixture();
        $otherOrganization = Organization::factory()->create();
        $operation = $this->operation($organization, 'knowledge/sources/'.$organization->getKey().'/scoped.txt');

        app(ProcessKnowledgeStorageCleanupOperation::class)->handle($otherOrganization->getKey(), $operation->getKey());

        $operation->refresh();
        self::assertSame(KnowledgeStorageCleanupStatus::Pending, $operation->status);
        self::assertSame(0, $operation->attempts);
    }

    public function test_scheduler_can_recover_a_pending_operation_after_dispatch_was_lost(): void
    {
        [$organization] = $this->fixture();
        $operation = $this->operation($organization, 'knowledge/sources/'.$organization->getKey().'/pending.txt');

        self::assertSame(1, app(ScheduleKnowledgeStorageCleanup::class)->handle());
        Queue::assertPushed(ProcessKnowledgeStorageCleanup::class, fn (ProcessKnowledgeStorageCleanup $job): bool => $job->organizationId === $organization->getKey()
            && $job->operationId === $operation->getKey());
    }

    /** @return array{0: Organization, 1: User} */
    private function fixture(): array
    {
        $organization = Organization::factory()->create();
        $actor = User::factory()->forOrganization($organization)->create();
        config()->set('tenancy.default_organization_id', $organization->getKey());
        app(OrganizationContext::class)->set($organization);

        return [$organization, $actor];
    }

    private function uploadedSource(User $actor, string $filename, string $content): KnowledgeSource
    {
        return app(CreateKnowledgeSource::class)->handle($actor, [
            'title' => 'Uploaded source',
            'type' => 'uploaded_text',
            'file' => UploadedFile::fake()->createWithContent($filename, $content),
        ]);
    }

    private function uploadedSourceReplacement(User $actor, KnowledgeSource $source, string $filename, string $content): KnowledgeRevision
    {
        $revision = app(UpdateKnowledgeSource::class)->handle($actor, $source, [
            'title' => $source->title,
            'file' => UploadedFile::fake()->createWithContent($filename, $content),
        ])->revision;
        self::assertInstanceOf(KnowledgeRevision::class, $revision);

        return $revision;
    }

    private function revisionPath(KnowledgeRevision $revision): string
    {
        self::assertIsString($revision->storage_path);

        return $revision->storage_path;
    }

    private function operation(Organization $organization, string $path, KnowledgeStorageCleanupStatus $status = KnowledgeStorageCleanupStatus::Pending): KnowledgeStorageCleanupOperation
    {
        return KnowledgeStorageCleanupOperation::query()->create([
            'organization_id' => $organization->getKey(),
            'cleanup_key' => hash('sha256', $organization->getKey().':'.$path),
            'storage_disk' => 'private',
            'storage_path' => $path,
            'status' => $status,
            'available_at' => now(),
        ]);
    }

    private function addRagReference(Organization $organization, KnowledgeSource $source, KnowledgeRevision $revision): void
    {
        $embedding = EmbeddingConfiguration::active();
        $chunking = ChunkingConfiguration::active();
        $run = KnowledgeIngestionRun::query()->create([
            'organization_id' => $organization->getKey(),
            'knowledge_source_id' => $source->getKey(),
            'knowledge_revision_id' => $revision->getKey(),
            'configuration_key' => 'cleanup-test-'.$revision->getKey(),
            'status' => 'ready',
            'chunk_strategy' => $chunking->strategy,
            'chunk_version' => $chunking->version,
            'chunk_target_characters' => $chunking->targetCharacters,
            'chunk_maximum_characters' => $chunking->maximumCharacters,
            'chunk_overlap_characters' => $chunking->overlapCharacters,
            'embedding_provider' => $embedding->provider,
            'embedding_model' => $embedding->model,
            'embedding_dimensions' => $embedding->dimensions,
            'embedding_configuration_version' => $embedding->version,
            'attempts' => 1,
            'completed_at' => now(),
        ]);
        $chunk = KnowledgeChunk::query()->create([
            'organization_id' => $organization->getKey(),
            'knowledge_source_id' => $source->getKey(),
            'knowledge_revision_id' => $revision->getKey(),
            'knowledge_ingestion_run_id' => $run->getKey(),
            'chunk_index' => 0,
            'start_offset' => 0,
            'end_offset' => 8,
            'source_reference' => null,
            'content_checksum' => hash('sha256', 'retained chunk'),
            'content' => 'retained chunk',
            'embedding' => array_fill(0, $embedding->dimensions, 0.0),
        ]);
        $aiRun = AiRun::query()->create([
            'organization_id' => $organization->getKey(),
            'capability' => AiCapability::ClientCompanion,
            'workflow_key' => 'cleanup-test',
            'status' => 'succeeded',
            'input_references' => [],
            'context_provenance' => [],
            'token_usage' => [],
        ]);
        AiRunRagReference::query()->create([
            'organization_id' => $organization->getKey(),
            'ai_run_id' => $aiRun->getKey(),
            'reference_index' => 0,
            'knowledge_source_id' => $source->getKey(),
            'knowledge_revision_id' => $revision->getKey(),
            'knowledge_chunk_id' => $chunk->getKey(),
            'retrieval_type' => 'initial',
            'chunk_index' => 0,
            'similarity_score' => 1.0,
            'configuration_key' => 'cleanup-test',
        ]);
    }
}
