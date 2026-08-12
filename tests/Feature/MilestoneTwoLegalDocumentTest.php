<?php

namespace Tests\Feature;

use App\Modules\Identity\Application\CreatePlatformLegalDocumentDraft;
use App\Modules\Identity\Application\ListPublishedLegalDocuments;
use App\Modules\Identity\Application\PublishLegalDocument;
use App\Modules\Identity\Application\RecordPortalClientConsents;
use App\Modules\Identity\Application\UpdateLegalDocumentDraft;
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
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use LogicException;
use Tests\TestCase;

class MilestoneTwoLegalDocumentTest extends TestCase
{
    use RefreshDatabase;

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
