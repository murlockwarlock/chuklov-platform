<?php

namespace Tests\Feature\Knowledge;

use App\Filament\Resources\KnowledgeSources\KnowledgeSourceResource;
use App\Models\User;
use App\Modules\Knowledge\Application\CreateKnowledgeSource;
use App\Modules\Knowledge\Application\GetTemporaryKnowledgeRevisionUrl;
use App\Modules\Knowledge\Application\RetryKnowledgeIngestion;
use App\Modules\Knowledge\Application\UpdateKnowledgeSource;
use App\Modules\Knowledge\Domain\Exceptions\KnowledgeRevisionFileUnavailable;
use App\Modules\Knowledge\Domain\Models\KnowledgeRevision;
use App\Modules\Knowledge\Domain\Models\KnowledgeSource;
use App\Modules\Knowledge\Jobs\IngestKnowledgeRevision;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Security\Domain\Models\AuditEvent;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
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
