<?php

namespace Tests\Integration;

use App\Filament\Resources\KnowledgeSources\Pages\EditKnowledgeSource;
use App\Filament\Resources\KnowledgeSources\Pages\ListKnowledgeSources;
use App\Filament\Resources\KnowledgeSources\RelationManagers\RevisionsRelationManager;
use App\Models\User;
use App\Modules\Knowledge\Domain\Models\KnowledgeIngestionRun;
use App\Modules\Knowledge\Domain\Models\KnowledgeRevision;
use App\Modules\Knowledge\Domain\Models\KnowledgeSource;
use App\Modules\Knowledge\Domain\ValueObjects\ChunkingConfiguration;
use App\Modules\Knowledge\Domain\ValueObjects\EmbeddingConfiguration;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Models\Organization;
use Filament\Facades\Filament;
use Filament\Tables\Contracts\HasTable;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

final class KnowledgeRevisionsFilamentPostgresTest extends TestCase
{
    use DatabaseTruncation;

    public function test_knowledge_filament_tables_use_postgres_safe_projection_and_tenant_scoping(): void
    {
        $this->requirePostgres('Knowledge Filament PostgreSQL projection requires PostgreSQL.');

        $fixture = $this->fixture();
        $organization = $fixture['organization'];
        $admin = $fixture['admin'];
        $source = $fixture['source'];
        $otherSource = $fixture['otherSource'];
        $foreignOrganization = $fixture['foreignOrganization'];
        $foreignAdmin = $fixture['foreignAdmin'];
        $foreignSource = $fixture['foreignSource'];
        $revisionOne = $fixture['revisionOne'];
        $revisionTwo = $fixture['revisionTwo'];
        $otherRevision = $fixture['otherRevision'];
        $foreignRevision = $fixture['foreignRevision'];
        $revisionOneProcessingRun = $fixture['revisionOneProcessingRun'];
        $revisionTwoReadyRun = $fixture['revisionTwoReadyRun'];

        $this->setFilamentContext($organization, $admin);

        /** @var Testable<ListKnowledgeSources> $sourceList */
        $sourceList = Livewire::actingAs($admin)->test(new ListKnowledgeSources);
        $sourceList->assertSuccessful();
        $sourceList
            ->loadTable()
            ->assertCanSeeTableRecords([$source, $otherSource])
            ->assertCanNotSeeTableRecords([$foreignSource]);

        /** @var Testable<RevisionsRelationManager> $revisions */
        $revisions = Livewire::actingAs($admin)->test(new RevisionsRelationManager, [
            'ownerRecord' => $source->refresh(),
            'pageClass' => EditKnowledgeSource::class,
        ]);

        $revisions->assertSuccessful();
        $revisions->loadTable();
        $records = $this->tableRecords($revisions->instance());
        $revisionOneRecord = $this->tableRevision($records, $revisionOne->getKey());
        $revisionTwoRecord = $this->tableRevision($records, $revisionTwo->getKey());
        $revisionOneLatestRun = $revisionOneRecord->latestIngestionRun;
        $revisionTwoLatestRun = $revisionTwoRecord->latestIngestionRun;

        self::assertInstanceOf(KnowledgeIngestionRun::class, $revisionOneLatestRun);
        self::assertInstanceOf(KnowledgeIngestionRun::class, $revisionTwoLatestRun);

        self::assertSame([$revisionTwo->getKey(), $revisionOne->getKey()], $records->pluck('id')->all());
        self::assertSame($revisionOneProcessingRun->getKey(), $revisionOneLatestRun->getKey());
        self::assertSame('processing', $revisionOneLatestRun->status->value);
        self::assertSame('embedding_or_persistence_failed', $revisionOneLatestRun->error_code);
        self::assertTrue((bool) $revisionOneRecord->getAttribute('has_compatible_ready_run'));
        self::assertTrue((bool) $revisionOneRecord->getAttribute('has_compatible_processing_run'));
        self::assertSame($revisionTwoReadyRun->getKey(), $revisionTwoLatestRun->getKey());
        self::assertNotNull($revisionTwoLatestRun->completed_at);
        self::assertTrue((bool) $revisionTwoRecord->getAttribute('has_compatible_ready_run'));
        self::assertFalse((bool) $revisionTwoRecord->getAttribute('has_compatible_processing_run'));
        self::assertNotContains($otherRevision->getKey(), $records->pluck('id')->all());
        self::assertNotContains($foreignRevision->getKey(), $records->pluck('id')->all());

        /** @var Testable<RevisionsRelationManager> $ascendingRevisions */
        $ascendingRevisions = Livewire::actingAs($admin)->test(new RevisionsRelationManager, [
            'ownerRecord' => $source->refresh(),
            'pageClass' => EditKnowledgeSource::class,
        ]);
        $ascendingRevisions->set('tableSort', 'version:asc')->loadTable();

        self::assertSame(
            [$revisionOne->getKey(), $revisionTwo->getKey()],
            $this->tableRecords($ascendingRevisions->instance())->pluck('id')->all(),
        );

        $this->setFilamentContext($foreignOrganization, $foreignAdmin);
        /** @var Testable<RevisionsRelationManager> $foreignRevisions */
        $foreignRevisions = Livewire::actingAs($foreignAdmin)->test(new RevisionsRelationManager, [
            'ownerRecord' => $foreignSource->refresh(),
            'pageClass' => EditKnowledgeSource::class,
        ]);

        $foreignRevisions->assertSuccessful();
        $foreignRevisions->loadTable();
        self::assertSame(
            [$foreignRevision->getKey()],
            $this->tableRecords($foreignRevisions->instance())->pluck('id')->all(),
        );
    }

    /**
     * @return array{
     *     organization: Organization,
     *     admin: User,
     *     source: KnowledgeSource,
     *     otherSource: KnowledgeSource,
     *     foreignOrganization: Organization,
     *     foreignAdmin: User,
     *     foreignSource: KnowledgeSource,
     *     revisionOne: KnowledgeRevision,
     *     revisionTwo: KnowledgeRevision,
     *     otherRevision: KnowledgeRevision,
     *     foreignRevision: KnowledgeRevision,
     *     revisionOneProcessingRun: KnowledgeIngestionRun,
     *     revisionTwoReadyRun: KnowledgeIngestionRun,
     * }
     */
    private function fixture(): array
    {
        $organization = Organization::factory()->create(['timezone' => 'UTC']);
        $admin = User::factory()->forOrganization($organization)->create();
        $foreignOrganization = Organization::factory()->create(['timezone' => 'UTC']);
        $foreignAdmin = User::factory()->forOrganization($foreignOrganization)->create();

        $source = KnowledgeSource::query()->create([
            'organization_id' => $organization->getKey(),
            'type' => 'authored_text',
            'title' => 'Synthetic source A',
            'status' => 'active',
        ]);
        $otherSource = KnowledgeSource::query()->create([
            'organization_id' => $organization->getKey(),
            'type' => 'authored_text',
            'title' => 'Synthetic source B',
            'status' => 'active',
        ]);
        $foreignSource = KnowledgeSource::query()->create([
            'organization_id' => $foreignOrganization->getKey(),
            'type' => 'authored_text',
            'title' => 'Synthetic foreign source',
            'status' => 'active',
        ]);

        $revisionOne = $this->createRevision($source, 1);
        $revisionTwo = $this->createRevision($source, 2);
        $otherRevision = $this->createRevision($otherSource, 1);
        $foreignRevision = $this->createRevision($foreignSource, 1);

        $source->update(['active_revision_id' => $revisionTwo->getKey()]);
        $otherSource->update(['active_revision_id' => $otherRevision->getKey()]);
        $foreignSource->update(['active_revision_id' => $foreignRevision->getKey()]);

        $this->createIngestionRun($source, $revisionOne, 'ready', 'ready', null);
        $revisionOneProcessingRun = $this->createIngestionRun(
            $source,
            $revisionOne,
            'processing',
            'processing',
            'embedding_or_persistence_failed',
        );
        $this->createIngestionRun($source, $revisionTwo, 'failed', 'legacy', 'embedding_or_persistence_failed');
        $revisionTwoReadyRun = $this->createIngestionRun($source, $revisionTwo, 'ready', 'ready', null);
        $this->createIngestionRun($otherSource, $otherRevision, 'ready', 'other', null);
        $this->createIngestionRun($foreignSource, $foreignRevision, 'ready', 'foreign', null);

        return [
            'organization' => $organization,
            'admin' => $admin,
            'source' => $source,
            'otherSource' => $otherSource,
            'foreignOrganization' => $foreignOrganization,
            'foreignAdmin' => $foreignAdmin,
            'foreignSource' => $foreignSource,
            'revisionOne' => $revisionOne,
            'revisionTwo' => $revisionTwo,
            'otherRevision' => $otherRevision,
            'foreignRevision' => $foreignRevision,
            'revisionOneProcessingRun' => $revisionOneProcessingRun,
            'revisionTwoReadyRun' => $revisionTwoReadyRun,
        ];
    }

    private function createRevision(KnowledgeSource $source, int $version): KnowledgeRevision
    {
        $content = "Synthetic knowledge source {$source->getKey()} revision {$version}";

        return KnowledgeRevision::query()->create([
            'organization_id' => $source->organization_id,
            'knowledge_source_id' => $source->getKey(),
            'version' => $version,
            'status' => 'ready',
            'content' => $content,
            'mime_type' => 'text/plain',
            'size_bytes' => strlen($content),
            'content_checksum' => hash('sha256', $content),
            'ready_at' => now(),
        ]);
    }

    private function createIngestionRun(
        KnowledgeSource $source,
        KnowledgeRevision $revision,
        string $status,
        string $configurationSuffix,
        ?string $errorCode,
    ): KnowledgeIngestionRun {
        $embedding = EmbeddingConfiguration::active();
        $chunking = ChunkingConfiguration::active();

        return KnowledgeIngestionRun::query()->create([
            'organization_id' => $source->organization_id,
            'knowledge_source_id' => $source->getKey(),
            'knowledge_revision_id' => $revision->getKey(),
            'configuration_key' => hash('sha256', "filament-pg-{$revision->getKey()}-{$configurationSuffix}"),
            'status' => $status,
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
            'error_code' => $errorCode,
            'processing_started_at' => $status === 'processing' ? now()->subMinute() : null,
            'completed_at' => $status === 'ready' ? now()->subMinute() : null,
        ]);
    }

    private function setFilamentContext(Organization $organization, User $admin): void
    {
        config()->set('tenancy.default_organization_id', $organization->getKey());
        app(OrganizationContext::class)->set($organization);
        $this->actingAs($admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    /** @return Collection<int, KnowledgeRevision> */
    private function tableRecords(HasTable $component): Collection
    {
        $records = $component->getTableRecords();

        if ($records instanceof Collection) {
            return $records;
        }

        if (method_exists($records, 'getCollection')) {
            return $records->getCollection();
        }

        return collect($records->items());
    }

    /** @param Collection<int, KnowledgeRevision> $records */
    private function tableRevision(Collection $records, int $id): KnowledgeRevision
    {
        $record = $records->firstWhere('id', $id);
        self::assertInstanceOf(KnowledgeRevision::class, $record);

        return $record;
    }

    private function requirePostgres(string $message): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped($message);
        }
    }
}
