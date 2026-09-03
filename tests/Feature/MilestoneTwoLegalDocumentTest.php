<?php

namespace Tests\Feature;

use App\Modules\Identity\Application\CreateLegalDocumentVersionDraft;
use App\Modules\Identity\Application\CreatePlatformLegalDocumentDraft;
use App\Modules\Identity\Application\ListPublishedLegalDocuments;
use App\Modules\Identity\Application\PublishLegalDocument;
use App\Modules\Identity\Application\RecordPortalClientConsents;
use App\Modules\Identity\Application\UpdateLegalDocumentDraft;
use App\Modules\Identity\Domain\Enums\ConsentSubject;
use App\Modules\Identity\Domain\Enums\LegalDocumentManagementMode;
use App\Modules\Identity\Domain\Enums\LegalDocumentStatus;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Identity\Domain\Models\ClientConsent;
use App\Modules\Identity\Domain\Models\LegalDocument;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationFeature;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Organizations\Domain\Models\OrganizationFeatureFlag;
use App\Modules\Security\Domain\Models\AuditEvent;
use Database\Seeders\LegalDocumentSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use LogicException;
use Tests\TestCase;

class MilestoneTwoLegalDocumentTest extends TestCase
{
    use RefreshDatabase;

    public function test_default_legal_document_seed_is_complete_and_idempotent(): void
    {
        $organization = $this->organizationWithClientRecords();

        app(LegalDocumentSeeder::class)->run();
        app(LegalDocumentSeeder::class)->run();

        $documents = LegalDocument::query()
            ->where('organization_id', $organization->getKey())
            ->get();

        self::assertCount(4, $documents);
        self::assertSame(['marketing', 'medical_disclaimer', 'offer', 'privacy'], $documents->pluck('document_type')->sort()->values()->all());
        self::assertSame(4, $documents->where('status', LegalDocumentStatus::Draft)->count());
        self::assertSame(3, $documents->where('is_required', true)->count());
        self::assertSame(1, $documents->where('is_required', false)->count());
        self::assertTrue($documents->every(static fn (LegalDocument $document): bool => str_starts_with($document->content, 'Черновик')));
    }

    public function test_platform_managed_draft_can_change_before_publish_but_published_content_is_immutable(): void
    {
        $organization = $this->organizationWithClientRecords();
        $draft = app(CreatePlatformLegalDocumentDraft::class)->handle(
            organization: $organization,
            documentType: 'privacy',
            purpose: 'privacy_consent',
            locale: 'en',
            version: '2026-08-12-v1',
            content: 'Draft text.',
            isRequired: true,
        );

        $updated = app(UpdateLegalDocumentDraft::class)->handle($draft, 'Updated draft text.');
        self::assertSame('Updated draft text.', $updated->content);

        $published = app(PublishLegalDocument::class)->handle($updated);
        self::assertSame(LegalDocumentStatus::Published, $published->status);
        self::assertNotNull($published->published_at);

        $published->content = 'Unsafe edit.';
        $this->expectException(LogicException::class);
        $published->save();
    }

    public function test_new_published_version_does_not_mutate_historical_consent_evidence(): void
    {
        $organization = $this->organizationWithClientRecords();
        $client = Client::factory()->forOrganization($organization)->create();
        $v1 = app(PublishLegalDocument::class)->handle($this->draft($organization, '2026-08-12-v1'));
        $v2 = app(PublishLegalDocument::class)->handle($this->draft($organization, '2026-08-12-v2'));

        app(OrganizationContext::class)->set($organization);
        app(RecordPortalClientConsents::class)->handle($client, [[
            'legal_document_id' => $v2->getKey(),
            'granted' => true,
        ]]);

        $v3 = app(PublishLegalDocument::class)->handle($this->draft($organization, '2026-08-12-v3'));
        $consent = ClientConsent::query()->sole();
        $v1->refresh();
        $v2->refresh();

        self::assertSame(LegalDocumentStatus::Archived, $v1->status);
        self::assertSame(LegalDocumentStatus::Archived, $v2->status);
        self::assertSame(LegalDocumentStatus::Published, $v3->status);
        self::assertSame($v2->id, $consent->legal_document_id);
        self::assertSame($v2->version, $consent->version);
        self::assertSame('Draft text 2026-08-12-v2.', $v2->content);
    }

    public function test_published_documents_are_scoped_to_the_current_organization(): void
    {
        $organization = $this->organizationWithClientRecords();
        $otherOrganization = Organization::factory()->create();
        $document = app(PublishLegalDocument::class)->handle($this->draft($organization, '2026-08-12-v1'));

        app(OrganizationContext::class)->set($otherOrganization);
        self::assertCount(0, app(ListPublishedLegalDocuments::class)->handle());

        $otherClient = Client::factory()->forOrganization($otherOrganization)->create();
        $this->expectException(AuthorizationException::class);
        app(RecordPortalClientConsents::class)->handle($otherClient, [[
            'legal_document_id' => $document->getKey(),
            'granted' => true,
        ]]);
    }

    public function test_organization_cannot_self_enable_organization_managed_legal_content(): void
    {
        $organization = $this->organizationWithClientRecords();

        $this->expectException(AuthorizationException::class);
        app(CreatePlatformLegalDocumentDraft::class)->handle(
            organization: $organization,
            documentType: 'privacy',
            purpose: 'privacy_consent',
            locale: 'en',
            version: '2026-08-12-v1',
            content: 'Text controlled by the platform.',
            isRequired: true,
            managementMode: LegalDocumentManagementMode::OrganizationManaged,
        );
    }

    public function test_required_documents_need_acceptance_and_audit_metadata_has_no_sensitive_material(): void
    {
        $organization = $this->organizationWithClientRecords();
        $client = Client::factory()->forOrganization($organization)->create();
        $document = app(PublishLegalDocument::class)->handle($this->draft($organization, '2026-08-12-v1'));
        app(OrganizationContext::class)->set($organization);

        try {
            app(RecordPortalClientConsents::class)->handle($client, [[
                'legal_document_id' => $document->getKey(),
                'granted' => false,
            ]]);
            self::fail('A required document must be accepted.');
        } catch (ValidationException) {
            self::assertSame(0, ClientConsent::query()->count());
        }

        app(RecordPortalClientConsents::class)->handle($client, [[
            'legal_document_id' => $document->getKey(),
            'granted' => true,
        ]]);

        $metadata = AuditEvent::query()->get()->toJson();
        self::assertStringNotContainsString('token', $metadata);
        self::assertStringNotContainsString('initData', $metadata);
        self::assertStringNotContainsString('Draft text', $metadata);
    }

    public function test_offer_privacy_and_medical_documents_are_required_but_marketing_is_optional(): void
    {
        $organization = $this->organizationWithClientRecords();
        $client = Client::factory()->forOrganization($organization)->create(['language' => 'en']);
        $documents = [];

        foreach ([
            'offer' => 'offer_consent',
            'privacy' => 'privacy_consent',
            'medical_disclaimer' => 'medical_consent',
            'marketing' => 'marketing_consent',
        ] as $documentType => $purpose) {
            $documents[$documentType] = app(PublishLegalDocument::class)->handle(
                app(CreatePlatformLegalDocumentDraft::class)->handle(
                    organization: $organization,
                    documentType: $documentType,
                    purpose: $purpose,
                    locale: 'en',
                    version: '2026-08-12-'.$documentType,
                    content: 'Configured '.$documentType.' text.',
                    isRequired: $documentType !== 'marketing',
                ),
            );
        }

        app(OrganizationContext::class)->set($organization);
        app(RecordPortalClientConsents::class)->handle($client, [
            ['legal_document_id' => $documents['offer']->getKey(), 'granted' => true],
            ['legal_document_id' => $documents['privacy']->getKey(), 'granted' => true],
            ['legal_document_id' => $documents['medical_disclaimer']->getKey(), 'granted' => true],
        ]);

        self::assertSame(3, ClientConsent::query()->count());
        self::assertSame(3, ClientConsent::query()->where('granted', true)->count());
        self::assertSame(0, ClientConsent::query()->where('subject', ConsentSubject::Marketing->value)->count());
    }

    public function test_missing_or_false_required_document_is_rejected_without_recording_partial_history(): void
    {
        $organization = $this->organizationWithClientRecords();
        $client = Client::factory()->forOrganization($organization)->create(['language' => 'en']);
        $offer = $this->publishDocument($organization, 'offer', 'offer_consent', true);
        $privacy = $this->publishDocument($organization, 'privacy', 'privacy_consent', true);
        $medical = $this->publishDocument($organization, 'medical_disclaimer', 'medical_consent', true);
        app(OrganizationContext::class)->set($organization);

        try {
            app(RecordPortalClientConsents::class)->handle($client, [[
                'legal_document_id' => $offer->getKey(),
                'granted' => true,
            ]]);
            self::fail('Every required document must be answered.');
        } catch (ValidationException) {
            self::assertSame(0, ClientConsent::query()->count());
        }

        $this->expectException(ValidationException::class);
        app(RecordPortalClientConsents::class)->handle($client, [
            ['legal_document_id' => $offer->getKey(), 'granted' => true],
            ['legal_document_id' => $privacy->getKey(), 'granted' => true],
            ['legal_document_id' => $medical->getKey(), 'granted' => false],
        ]);
    }

    public function test_new_version_is_a_draft_and_published_source_remains_immutable(): void
    {
        $organization = $this->organizationWithClientRecords();
        $published = $this->publishDocument($organization, 'privacy', 'privacy_consent', true);

        $draft = app(CreateLegalDocumentVersionDraft::class)->handle(
            source: $published,
            version: '2026-08-12-v2',
            content: 'Updated configured privacy text.',
        );

        self::assertNotSame($published->getKey(), $draft->getKey());
        self::assertSame(LegalDocumentStatus::Draft, $draft->status);
        self::assertSame('Updated configured privacy text.', $draft->content);
        self::assertSame(LegalDocumentStatus::Published, $published->refresh()->status);
        self::assertSame('Draft text 2026-08-12-v1.', $published->content);

        $published->status = LegalDocumentStatus::Draft;
        $this->expectException(LogicException::class);
        $published->save();
    }

    public function test_published_and_archived_versions_cannot_be_deleted(): void
    {
        $organization = $this->organizationWithClientRecords();
        $archived = $this->publishDocument($organization, 'privacy', 'privacy_consent', true);
        $published = app(PublishLegalDocument::class)->handle(
            app(CreatePlatformLegalDocumentDraft::class)->handle(
                organization: $organization,
                documentType: 'privacy',
                purpose: 'privacy_consent',
                locale: 'en',
                version: '2026-08-12-v2-privacy',
                content: 'Draft text 2026-08-12-v2.',
                isRequired: true,
            ),
        );

        try {
            $published->delete();
            self::fail('A published legal document version must be immutable.');
        } catch (LogicException) {
            self::assertDatabaseHas('legal_documents', ['id' => $published->getKey()]);
        }

        $this->expectException(LogicException::class);
        $archived->delete();
    }

    public function test_archived_version_cannot_be_reactivated_or_changed(): void
    {
        $organization = $this->organizationWithClientRecords();
        $archived = $this->publishDocument($organization, 'privacy', 'privacy_consent', true);
        app(PublishLegalDocument::class)->handle(
            app(CreatePlatformLegalDocumentDraft::class)->handle(
                organization: $organization,
                documentType: 'privacy',
                purpose: 'privacy_consent',
                locale: 'en',
                version: '2026-08-12-v2-privacy',
                content: 'Draft text 2026-08-12-v2.',
                isRequired: true,
            ),
        );

        $archived->refresh();
        $archived->status = LegalDocumentStatus::Published;
        $this->expectException(LogicException::class);
        $archived->save();
    }

    public function test_legal_document_draft_accepts_only_configured_consent_subjects(): void
    {
        $organization = $this->organizationWithClientRecords();

        $this->expectException(\InvalidArgumentException::class);
        app(CreatePlatformLegalDocumentDraft::class)->handle(
            organization: $organization,
            documentType: 'custom_notice',
            purpose: 'custom_notice',
            locale: 'en',
            version: '2026-08-12-v1',
            content: 'Configured text.',
            isRequired: false,
        );
    }

    private function draft(Organization $organization, string $version): LegalDocument
    {
        return app(CreatePlatformLegalDocumentDraft::class)->handle(
            organization: $organization,
            documentType: 'privacy',
            purpose: 'privacy_consent',
            locale: 'en',
            version: $version,
            content: 'Draft text '.$version.'.',
            isRequired: true,
        );
    }

    private function publishDocument(Organization $organization, string $documentType, string $purpose, bool $isRequired): LegalDocument
    {
        return app(PublishLegalDocument::class)->handle(
            app(CreatePlatformLegalDocumentDraft::class)->handle(
                organization: $organization,
                documentType: $documentType,
                purpose: $purpose,
                locale: 'en',
                version: '2026-08-12-v1-'.$documentType,
                content: 'Draft text 2026-08-12-v1.',
                isRequired: $isRequired,
            ),
        );
    }

    private function organizationWithClientRecords(): Organization
    {
        $organization = Organization::factory()->create();
        OrganizationFeatureFlag::factory()->forOrganization($organization)->create([
            'feature_key' => OrganizationFeature::ClientRecords->value,
            'enabled' => true,
        ]);
        config()->set('tenancy.default_organization_id', $organization->id);
        app(OrganizationContext::class)->set($organization);

        return $organization;
    }
}
