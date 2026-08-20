<?php

namespace Tests\Feature\Knowledge;

use App\Filament\Pages\KnowledgeRetrievalInspector;
use App\Filament\Resources\KnowledgeSources\KnowledgeSourceResource;
use App\Filament\Support\KnowledgeSourcePresentation;
use App\Models\User;
use App\Modules\Knowledge\Application\ClaimKnowledgeIngestionRun;
use App\Modules\Knowledge\Application\CreateKnowledgeSource;
use App\Modules\Knowledge\Application\GetTemporaryKnowledgeRevisionUrl;
use App\Modules\Knowledge\Application\ReprocessKnowledgeForSearch;
use App\Modules\Knowledge\Application\RetireKnowledgeSource;
use App\Modules\Knowledge\Application\RetryKnowledgeIngestion;
use App\Modules\Knowledge\Application\StartPendingKnowledgeIngestion;
use App\Modules\Knowledge\Application\UpdateKnowledgeSource;
use App\Modules\Knowledge\Domain\Exceptions\KnowledgeRevisionFileUnavailable;
use App\Modules\Knowledge\Domain\Models\KnowledgeRevision;
use App\Modules\Knowledge\Domain\Models\KnowledgeSource;
use App\Modules\Knowledge\Domain\ValueObjects\ChunkingConfiguration;
use App\Modules\Knowledge\Domain\ValueObjects\EmbeddingConfiguration;
use App\Modules\Knowledge\Jobs\IngestKnowledgeRevision;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationRole;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Security\Application\RecordAuditEvent;
use App\Modules\Security\Domain\Models\AuditEvent;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Tests\TestCase;

final class KnowledgeSourceProductizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Storage::fake('private');
    }

    public function test_metadata_only_update_returns_no_revision_and_records_source_change(): void
    {
        [, $actor] = $this->fixture();
        $source = $this->createAuthoredSource($actor, 'booking guide');

        $result = app(UpdateKnowledgeSource::class)->handle($actor, $source, [
            'title' => 'Updated booking guide',
            'category' => 'Appointments',
        ]);

        self::assertFalse($result->revisionCreated);
        self::assertNull($result->revision);
        self::assertSame(1, $source->revisions()->count());
        self::assertSame('Updated booking guide', $result->source->title);
        self::assertSame('Appointments', $result->source->category);
        self::assertSame('knowledge.source.updated', AuditEvent::query()->where('action', 'knowledge.source.updated')->sole()->action);
        Queue::assertPushed(IngestKnowledgeRevision::class, 1);
    }

    public function test_unchanged_authored_content_does_not_create_revision_or_clear_provenance(): void
    {
        [, $actor] = $this->fixture();
        $source = app(CreateKnowledgeSource::class)->handle($actor, [
            'title' => 'Guide',
            'type' => 'authored_text',
            'content' => 'booking content',
            'source_reference' => 'handbook#booking',
        ]);

        $result = app(UpdateKnowledgeSource::class)->handle($actor, $source, ['content' => 'booking content']);

        self::assertFalse($result->revisionCreated);
        self::assertNull($result->revision);
        self::assertSame(1, $source->revisions()->count());
        self::assertSame('handbook#booking', $source->revisions()->sole()->source_reference);
        Queue::assertPushed(IngestKnowledgeRevision::class, 1);
    }

    public function test_uploaded_update_without_a_new_file_keeps_existing_material(): void
    {
        [, $actor] = $this->fixture();
        $source = app(CreateKnowledgeSource::class)->handle($actor, [
            'title' => 'Uploaded guide',
            'type' => 'uploaded_text',
            'file' => UploadedFile::fake()->createWithContent('guide.md', 'booking content'),
        ]);
        $revision = $source->revisions()->sole();

        $result = app(UpdateKnowledgeSource::class)->handle($actor, $source, ['title' => 'Uploaded guide renamed']);

        self::assertFalse($result->revisionCreated);
        self::assertNull($result->revision);
        self::assertSame(1, $source->revisions()->count());
        Storage::disk('private')->assertExists($revision->storage_path);
    }

    public function test_real_replacement_creates_next_revision_and_preserves_omitted_provenance(): void
    {
        [, $actor] = $this->fixture();
        $source = app(CreateKnowledgeSource::class)->handle($actor, [
            'title' => 'Guide',
            'type' => 'authored_text',
            'content' => 'first booking content',
            'source_reference' => 'handbook#booking',
        ]);

        $result = app(UpdateKnowledgeSource::class)->handle($actor, $source, [
            'content' => 'second booking content',
        ]);

        self::assertTrue($result->revisionCreated);
        self::assertInstanceOf(KnowledgeRevision::class, $result->revision);
        self::assertSame(2, $result->revision->version);
        self::assertSame('handbook#booking', $result->revision->source_reference);
        self::assertSame(2, $source->revisions()->count());
        Queue::assertPushed(IngestKnowledgeRevision::class, 2);
    }

    public function test_uploaded_replacement_creates_an_immutable_next_revision_and_keeps_old_file(): void
    {
        [, $actor] = $this->fixture();
        $source = app(CreateKnowledgeSource::class)->handle($actor, [
            'title' => 'Uploaded guide',
            'type' => 'uploaded_text',
            'file' => UploadedFile::fake()->createWithContent('first.txt', 'first booking content'),
            'source_reference' => 'imported/file',
        ]);
        $oldRevision = $source->revisions()->sole();

        $result = app(UpdateKnowledgeSource::class)->handle($actor, $source, [
            'file' => UploadedFile::fake()->createWithContent('second.txt', 'second booking content'),
        ]);

        self::assertTrue($result->revisionCreated);
        self::assertInstanceOf(KnowledgeRevision::class, $result->revision);
        self::assertNotSame($oldRevision->storage_path, $result->revision->storage_path);
        self::assertSame('imported/file', $result->revision->source_reference);
        Storage::disk('private')->assertExists($oldRevision->storage_path);
        Storage::disk('private')->assertExists($result->revision->storage_path);
    }

    public function test_source_reference_only_mutation_is_not_a_metadata_edit(): void
    {
        [, $actor] = $this->fixture();
        $source = app(CreateKnowledgeSource::class)->handle($actor, [
            'title' => 'Guide',
            'type' => 'authored_text',
            'content' => 'booking content',
            'source_reference' => 'original',
        ]);

        $this->expectException(ValidationException::class);
        app(UpdateKnowledgeSource::class)->handle($actor, $source, ['source_reference' => 'rewritten']);
    }

    public function test_manual_retry_only_queues_the_current_failed_revision_and_guards_duplicate_clicks(): void
    {
        [$organization, $actor] = $this->fixture();
        $source = $this->createAuthoredSource($actor, 'booking content');
        $revision = $source->revisions()->sole();
        $revision->update(['status' => 'failed']);

        $retried = app(RetryKnowledgeIngestion::class)->handle($actor, $source, $revision->getKey());

        self::assertSame('pending', $retried->status->value);
        Queue::assertPushed(IngestKnowledgeRevision::class, 2);
        $audit = AuditEvent::query()->where('action', 'knowledge.ingestion.retry_requested')->sole();
        self::assertSame($organization->getKey(), $audit->organization_id);

        $this->expectException(ValidationException::class);
        app(RetryKnowledgeIngestion::class)->handle($actor, $source, $revision->getKey());
    }

    public function test_manual_retry_dispatch_failure_restores_failed_state_and_allows_a_new_request(): void
    {
        [, $actor] = $this->fixture();
        $source = $this->createAuthoredSource($actor, 'booking content');
        $revision = $source->revisions()->sole();
        $revision->update(['status' => 'failed']);
        $dispatchCount = 0;
        $dispatcher = \Mockery::mock(Dispatcher::class);
        $dispatcher->shouldReceive('dispatch')->twice()->andReturnUsing(function () use (&$dispatchCount): mixed {
            $dispatchCount++;
            if ($dispatchCount === 1) {
                throw new RuntimeException('redis unavailable');
            }

            return null;
        });
        $this->app->instance(Dispatcher::class, $dispatcher);

        try {
            app(RetryKnowledgeIngestion::class)->handle($actor, $source, $revision->getKey());
            self::fail('Dispatch failure did not surface a safe validation error.');
        } catch (ValidationException $exception) {
            self::assertStringContainsString('Не удалось запустить повторную обработку.', $exception->getMessage());
            self::assertStringNotContainsString('redis unavailable', $exception->getMessage());
        }

        self::assertSame('failed', $revision->fresh()->status->value);
        self::assertSame(1, AuditEvent::query()->where('action', 'knowledge.ingestion.retry_dispatch_failed')->count());

        app(RetryKnowledgeIngestion::class)->handle($actor, $source, $revision->getKey());

        self::assertSame('pending', $revision->fresh()->status->value);
        self::assertSame(2, $dispatchCount);
    }

    public function test_manual_retry_dispatch_compensation_does_not_overwrite_authoritative_progress(): void
    {
        [, $actor] = $this->fixture();
        $source = $this->createAuthoredSource($actor, 'booking content');
        $revision = $source->revisions()->sole();
        $revision->update(['status' => 'failed']);
        $dispatcher = \Mockery::mock(Dispatcher::class);
        $dispatcher->shouldReceive('dispatch')->once()->andReturnUsing(function () use ($revision): mixed {
            DB::table('knowledge_revisions')->where('id', $revision->getKey())->update(['status' => 'processing']);
            throw new RuntimeException('redis unavailable after claim');
        });
        $this->app->instance(Dispatcher::class, $dispatcher);

        try {
            app(RetryKnowledgeIngestion::class)->handle($actor, $source, $revision->getKey());
            self::fail('Dispatch failure did not surface a safe validation error.');
        } catch (ValidationException $exception) {
            self::assertStringNotContainsString('redis unavailable after claim', $exception->getMessage());
        }

        self::assertSame('processing', $revision->fresh()->status->value);
        self::assertSame(0, AuditEvent::query()->where('action', 'knowledge.ingestion.retry_dispatch_failed')->count());
    }

    public function test_failed_retry_remains_recoverable_when_compensation_cannot_record_audit(): void
    {
        [, $actor] = $this->fixture();
        $source = $this->createAuthoredSource($actor, 'booking content');
        $revision = $source->revisions()->sole();
        $revision->update(['status' => 'failed']);

        $dispatchCount = 0;
        $dispatcher = \Mockery::mock(Dispatcher::class);
        $dispatcher->shouldReceive('dispatch')->twice()->andReturnUsing(function () use (&$dispatchCount): mixed {
            $dispatchCount++;
            if ($dispatchCount === 1) {
                throw new RuntimeException('redis unavailable');
            }

            return null;
        });
        $this->app->instance(Dispatcher::class, $dispatcher);

        $auditCalls = 0;
        $audit = $this->createMock(RecordAuditEvent::class);
        $audit->expects($this->exactly(3))->method('handle')->willReturnCallback(function (...$arguments) use (&$auditCalls): AuditEvent {
            unset($arguments);
            $auditCalls++;
            if ($auditCalls === 2) {
                throw new RuntimeException('audit unavailable');
            }

            return new AuditEvent;
        });
        $this->app->instance(RecordAuditEvent::class, $audit);

        try {
            app(RetryKnowledgeIngestion::class)->handle($actor, $source, $revision->getKey());
            self::fail('Dispatch failure did not surface a safe validation error.');
        } catch (ValidationException $exception) {
            self::assertStringContainsString('Не удалось запустить повторную обработку.', $exception->getMessage());
            self::assertStringNotContainsString('audit unavailable', $exception->getMessage());
        }

        self::assertSame('pending', $revision->fresh()->status->value);
        app(StartPendingKnowledgeIngestion::class)->handle($actor, $source->fresh(), $revision->getKey());
        self::assertSame('pending', $revision->fresh()->status->value);
        self::assertSame(2, $dispatchCount);
    }

    public function test_create_dispatch_failure_keeps_pending_revision_available_for_later_start(): void
    {
        [, $actor] = $this->fixture();
        $dispatchCount = 0;
        $dispatcher = \Mockery::mock(Dispatcher::class);
        $dispatcher->shouldReceive('dispatch')->times(3)->andReturnUsing(function () use (&$dispatchCount): mixed {
            $dispatchCount++;
            if (in_array($dispatchCount, [1, 2], true)) {
                throw new RuntimeException('redis unavailable');
            }

            return null;
        });
        $this->app->instance(Dispatcher::class, $dispatcher);

        $source = app(CreateKnowledgeSource::class)->handle($actor, [
            'title' => 'Guide',
            'type' => 'authored_text',
            'content' => 'booking content',
        ]);
        $revision = $source->revisions()->sole();

        self::assertSame('pending', $revision->status->value);
        self::assertSame(1, AuditEvent::query()->where('action', 'knowledge.ingestion.dispatch_failed')->whereJsonContains('metadata->operation', 'create')->count());

        try {
            app(StartPendingKnowledgeIngestion::class)->handle($actor, $source->fresh(), $revision->getKey());
            self::fail('Pending dispatch failure did not surface a safe validation error.');
        } catch (ValidationException $exception) {
            self::assertStringContainsString('Не удалось запустить обработку.', $exception->getMessage());
            self::assertStringNotContainsString('redis unavailable', $exception->getMessage());
        }

        self::assertSame('pending', $revision->fresh()->status->value);
        app(StartPendingKnowledgeIngestion::class)->handle($actor, $source->fresh(), $revision->getKey());

        self::assertSame('pending', $revision->fresh()->status->value);
        self::assertSame(3, $dispatchCount);
    }

    public function test_replacement_dispatch_failure_keeps_previous_active_material_and_new_pending_revision(): void
    {
        [, $actor] = $this->fixture();
        $source = app(CreateKnowledgeSource::class)->handle($actor, [
            'title' => 'Uploaded guide',
            'type' => 'uploaded_text',
            'file' => UploadedFile::fake()->createWithContent('first.txt', 'first booking content'),
        ]);
        $oldRevision = $source->revisions()->sole();
        $oldRevision->update(['status' => 'ready', 'ready_at' => now()]);
        $source->update(['active_revision_id' => $oldRevision->getKey()]);

        $dispatchCount = 0;
        $dispatcher = \Mockery::mock(Dispatcher::class);
        $dispatcher->shouldReceive('dispatch')->twice()->andReturnUsing(function () use (&$dispatchCount): mixed {
            $dispatchCount++;
            if ($dispatchCount === 1) {
                throw new RuntimeException('redis unavailable');
            }

            return null;
        });
        $this->app->instance(Dispatcher::class, $dispatcher);

        $result = app(UpdateKnowledgeSource::class)->handle($actor, $source->fresh(), [
            'file' => UploadedFile::fake()->createWithContent('second.txt', 'second booking content'),
        ]);
        $newRevision = $result->revision;
        self::assertInstanceOf(KnowledgeRevision::class, $newRevision);

        self::assertSame('active', $result->source->fresh()->status->value);
        self::assertSame($oldRevision->getKey(), $result->source->fresh()->active_revision_id);
        self::assertSame('pending', $newRevision->fresh()->status->value);
        self::assertSame(2, $result->source->revisions()->count());
        Storage::disk('private')->assertExists($newRevision->storage_path);
        self::assertSame(1, AuditEvent::query()->where('action', 'knowledge.ingestion.dispatch_failed')->whereJsonContains('metadata->operation', 'replacement')->count());

        app(StartPendingKnowledgeIngestion::class)->handle($actor, $result->source->fresh(), $newRevision->getKey());
        self::assertSame('pending', $newRevision->fresh()->status->value);
        self::assertSame(2, $dispatchCount);
    }

    public function test_pending_start_requires_active_latest_pending_revision_and_manage_permission(): void
    {
        [$organization, $actor] = $this->fixture();
        $source = $this->createAuthoredSource($actor, 'booking content');
        $revision = $source->revisions()->sole();

        $staff = User::factory()->forOrganization($organization, OrganizationRole::Staff)->create();
        try {
            app(StartPendingKnowledgeIngestion::class)->handle($staff, $source, $revision->getKey());
            self::fail('A user without ManageKnowledge started pending processing.');
        } catch (AuthorizationException) {
            self::assertTrue(true);
        }

        $otherOrganization = Organization::factory()->create();
        $otherActor = User::factory()->forOrganization($otherOrganization)->create();
        app(OrganizationContext::class)->set($otherOrganization);
        try {
            app(StartPendingKnowledgeIngestion::class)->handle($otherActor, $source, $revision->getKey());
            self::fail('A cross-tenant pending processing request was accepted.');
        } catch (AuthorizationException) {
            self::assertTrue(true);
        } finally {
            app(OrganizationContext::class)->set($organization);
        }
    }

    public function test_pending_start_rejects_retired_superseded_and_non_pending_revisions(): void
    {
        [, $actor] = $this->fixture();
        $retiredSource = $this->createAuthoredSource($actor, 'retired booking content');
        $retiredRevision = $retiredSource->revisions()->sole();
        $retiredSource->update(['status' => 'retired']);
        try {
            app(StartPendingKnowledgeIngestion::class)->handle($actor, $retiredSource, $retiredRevision->getKey());
            self::fail('A retired source accepted pending processing.');
        } catch (ValidationException) {
            self::assertTrue(true);
        }

        $supersededSource = $this->createAuthoredSource($actor, 'first booking content');
        $supersededRevision = $supersededSource->revisions()->sole();
        app(UpdateKnowledgeSource::class)->handle($actor, $supersededSource, ['content' => 'second booking content']);
        try {
            app(StartPendingKnowledgeIngestion::class)->handle($actor, $supersededSource, $supersededRevision->getKey());
            self::fail('A superseded pending revision accepted processing.');
        } catch (ValidationException) {
            self::assertTrue(true);
        }

        $readySource = $this->createAuthoredSource($actor, 'ready booking content');
        $readyRevision = $readySource->revisions()->sole();
        $readyRevision->update(['status' => 'ready']);
        try {
            app(StartPendingKnowledgeIngestion::class)->handle($actor, $readySource, $readyRevision->getKey());
            self::fail('A non-pending revision accepted pending processing.');
        } catch (ValidationException) {
            self::assertTrue(true);
        }
    }

    public function test_duplicate_pending_starts_are_collapsed_by_the_authoritative_claim(): void
    {
        [, $actor] = $this->fixture();
        $source = $this->createAuthoredSource($actor, 'booking content');
        $revision = $source->revisions()->sole();

        app(StartPendingKnowledgeIngestion::class)->handle($actor, $source, $revision->getKey());
        app(StartPendingKnowledgeIngestion::class)->handle($actor, $source, $revision->getKey());
        Queue::assertPushed(IngestKnowledgeRevision::class, 3);

        $firstClaim = app(ClaimKnowledgeIngestionRun::class)->handle(
            $source->organization_id,
            $source->getKey(),
            $revision->getKey(),
            EmbeddingConfiguration::active(),
            ChunkingConfiguration::active(),
        );
        $secondClaim = app(ClaimKnowledgeIngestionRun::class)->handle(
            $source->organization_id,
            $source->getKey(),
            $revision->getKey(),
            EmbeddingConfiguration::active(),
            ChunkingConfiguration::active(),
        );

        self::assertNotNull($firstClaim);
        self::assertNull($secondClaim);
        self::assertSame(1, $revision->ingestionRuns()->count());
        self::assertSame(1, $revision->ingestionRuns()->sole()->attempts);
    }

    public function test_ready_active_material_exposes_human_search_reprocessing_and_dispatches_without_a_new_revision(): void
    {
        [, $actor] = $this->fixture();
        $source = $this->createAuthoredSource($actor, 'booking content');
        $revision = $source->revisions()->sole();
        $revision->update(['status' => 'ready']);
        $source->update(['active_revision_id' => $revision->getKey()]);
        $revision->setAttribute('has_compatible_ready_run', false);
        $revision->setAttribute('has_compatible_processing_run', false);

        self::assertTrue(app(KnowledgeSourcePresentation::class)->canReprocessForSearch($source->fresh(), $revision));
        app(ReprocessKnowledgeForSearch::class)->handle($actor, $source->fresh(), $revision->getKey());

        Queue::assertPushed(IngestKnowledgeRevision::class, 2);
        self::assertSame(1, $source->revisions()->count());
        self::assertSame('ready', $revision->fresh()->status->value);
        self::assertSame(1, AuditEvent::query()->where('action', 'knowledge.ingestion.reprocess_requested')->count());
    }

    public function test_search_reprocessing_fails_closed_for_permission_tenant_lifecycle_and_active_revision_guards(): void
    {
        [$organization, $actor] = $this->fixture();
        $source = $this->createAuthoredSource($actor, 'booking content');
        $revision = $source->revisions()->sole();
        $revision->update(['status' => 'ready']);
        $source->update(['active_revision_id' => $revision->getKey()]);

        $staff = User::factory()->forOrganization($organization, OrganizationRole::Staff)->create();
        $this->expectException(AuthorizationException::class);
        app(ReprocessKnowledgeForSearch::class)->handle($staff, $source, $revision->getKey());
    }

    public function test_search_reprocessing_rejects_cross_tenant_retired_and_non_active_revisions(): void
    {
        [$organization, $actor] = $this->fixture();
        $source = $this->createAuthoredSource($actor, 'booking content');
        $revision = $source->revisions()->sole();
        $revision->update(['status' => 'ready']);
        $source->update(['active_revision_id' => $revision->getKey()]);

        $otherOrganization = Organization::factory()->create();
        $otherActor = User::factory()->forOrganization($otherOrganization)->create();
        app(OrganizationContext::class)->set($otherOrganization);
        try {
            app(ReprocessKnowledgeForSearch::class)->handle($otherActor, $source, $revision->getKey());
            self::fail('A cross-tenant reprocess request was accepted.');
        } catch (AuthorizationException) {
            self::assertTrue(true);
        } finally {
            app(OrganizationContext::class)->set($organization);
        }

        $secondSource = $this->createAuthoredSource($actor, 'second booking content');
        $secondRevision = $secondSource->revisions()->sole();
        $secondRevision->update(['status' => 'ready']);
        $secondSource->update(['active_revision_id' => $secondRevision->getKey()]);
        $replacement = app(UpdateKnowledgeSource::class)->handle($actor, $secondSource->fresh(), ['content' => 'third booking content']);
        $nonActiveRevision = $replacement->revision;
        self::assertInstanceOf(KnowledgeRevision::class, $nonActiveRevision);
        $nonActiveRevision->update(['status' => 'ready']);
        try {
            app(ReprocessKnowledgeForSearch::class)->handle($actor, $secondSource->fresh(), $nonActiveRevision->getKey());
            self::fail('A non-active revision was accepted for search reprocessing.');
        } catch (ValidationException) {
            self::assertTrue(true);
        }

        app(RetireKnowledgeSource::class)->handle($actor, $source->fresh());
        try {
            app(ReprocessKnowledgeForSearch::class)->handle($actor, $source->fresh(), $revision->getKey());
            self::fail('A retired source was accepted for search reprocessing.');
        } catch (ValidationException) {
            self::assertTrue(true);
        }
    }

    public function test_manual_retry_rejects_superseded_and_cross_organization_revisions(): void
    {
        [, $actor] = $this->fixture();
        $source = $this->createAuthoredSource($actor, 'first booking content');
        $firstRevision = $source->revisions()->sole();
        $firstRevision->update(['status' => 'failed']);
        app(UpdateKnowledgeSource::class)->handle($actor, $source, ['content' => 'second booking content']);

        try {
            app(RetryKnowledgeIngestion::class)->handle($actor, $source, $firstRevision->getKey());
            self::fail('A superseded revision was retried.');
        } catch (ValidationException) {
            self::assertTrue(true);
        }

        $otherOrganization = Organization::factory()->create();
        $otherActor = User::factory()->forOrganization($otherOrganization)->create();
        app(OrganizationContext::class)->set($otherOrganization);
        $this->expectException(AuthorizationException::class);
        app(RetryKnowledgeIngestion::class)->handle($otherActor, $source, $firstRevision->getKey());
    }

    public function test_download_requires_uploaded_revision_and_uses_record_scoped_signed_route(): void
    {
        [$organization, $actor] = $this->fixture();
        $source = app(CreateKnowledgeSource::class)->handle($actor, [
            'title' => 'Uploaded guide',
            'type' => 'uploaded_text',
            'file' => UploadedFile::fake()->createWithContent('guide.txt', 'private booking material'),
        ]);
        $revision = $source->revisions()->sole();
        $signedUrl = app(GetTemporaryKnowledgeRevisionUrl::class)->handle($actor, $source, $revision);

        $response = $this->actingAs($actor)->get($signedUrl);

        $response->assertOk();
        self::assertStringStartsWith('text/plain', (string) $response->headers->get('Content-Type'));
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        self::assertStringNotContainsString($revision->storage_path, (string) $response->headers->get('Content-Disposition'));

        $otherOrganization = $this->organization();
        config()->set('tenancy.default_organization_id', $otherOrganization->getKey());
        $otherActor = User::factory()->forOrganization($otherOrganization)->create();
        app(OrganizationContext::class)->set($otherOrganization);
        $this->actingAs($otherActor)->get($signedUrl)->assertNotFound();
        app(OrganizationContext::class)->set($organization);
    }

    public function test_download_denies_unauthenticated_and_authored_revisions(): void
    {
        [, $actor] = $this->fixture();
        $uploadedSource = app(CreateKnowledgeSource::class)->handle($actor, [
            'title' => 'Uploaded guide',
            'type' => 'uploaded_text',
            'file' => UploadedFile::fake()->createWithContent('guide.txt', 'private booking material'),
        ]);
        $uploadedRevision = $uploadedSource->revisions()->sole();
        $signedUrl = app(GetTemporaryKnowledgeRevisionUrl::class)->handle($actor, $uploadedSource, $uploadedRevision);
        $this->get($signedUrl)->assertRedirect('/admin/login');

        $authoredSource = $this->createAuthoredSource($actor, 'booking content');
        $authoredRevision = $authoredSource->revisions()->sole();
        $this->expectException(KnowledgeRevisionFileUnavailable::class);
        app(GetTemporaryKnowledgeRevisionUrl::class)->handle($actor, $authoredSource, $authoredRevision);
    }

    public function test_missing_uploaded_file_fails_closed_and_filename_is_safe(): void
    {
        [, $actor] = $this->fixture();
        $source = app(CreateKnowledgeSource::class)->handle($actor, [
            'title' => 'Uploaded guide',
            'type' => 'uploaded_text',
            'file' => UploadedFile::fake()->createWithContent('guide.txt', 'booking content'),
        ]);
        $revision = $source->revisions()->sole();
        DB::table('knowledge_revisions')->where('id', $revision->getKey())->update([
            'original_filename' => "../unsafe\r\nname.txt",
        ]);
        $signedUrl = app(GetTemporaryKnowledgeRevisionUrl::class)->handle($actor, $source, $revision);
        $response = $this->actingAs($actor)->get($signedUrl);
        $response->assertOk();
        self::assertStringNotContainsString("\r", (string) $response->headers->get('Content-Disposition'));
        self::assertStringNotContainsString("\n", (string) $response->headers->get('Content-Disposition'));
        self::assertStringNotContainsString('storage', strtolower((string) $response->headers->get('Content-Disposition')));

        Storage::disk('private')->delete($revision->storage_path);
        $this->actingAs($actor)->get($signedUrl)->assertNotFound();
    }

    public function test_knowledge_edit_history_uses_bounded_human_fields(): void
    {
        [, $actor] = $this->fixture();
        $source = $this->createAuthoredSource($actor, 'booking content');
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $this->actingAs($actor)
            ->get(KnowledgeSourceResource::getUrl('edit', ['record' => $source]))
            ->assertOk()
            ->assertDontSee('source_reference')
            ->assertDontSee('similarity');
    }

    public function test_retrieval_inspector_uses_human_fragment_count_label(): void
    {
        [, $actor] = $this->fixture();
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $this->actingAs($actor)
            ->get(KnowledgeRetrievalInspector::getUrl())
            ->assertOk()
            ->assertSee('Количество фрагментов')
            ->assertDontSee('Показать результатов');
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

    private function organization(): Organization
    {
        return Organization::factory()->create();
    }

    private function createAuthoredSource(User $actor, string $content): KnowledgeSource
    {
        return app(CreateKnowledgeSource::class)->handle($actor, [
            'title' => 'Guide',
            'type' => 'authored_text',
            'content' => $content,
        ]);
    }
}
