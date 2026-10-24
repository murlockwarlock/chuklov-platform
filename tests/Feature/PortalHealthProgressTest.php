<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\AI\Domain\Enums\AiCapability;
use App\Modules\AI\Domain\Enums\AiExecutionMode;
use App\Modules\AI\Domain\Enums\AiRunOrigin;
use App\Modules\AI\Domain\Enums\AiRunStatus;
use App\Modules\AI\Domain\Enums\ClinicalSynthesizerWorkflow;
use App\Modules\AI\Domain\Enums\HumanReviewStatus;
use App\Modules\AI\Domain\Models\AiRun;
use App\Modules\AI\Domain\Models\AiRunPayload;
use App\Modules\Attachments\Application\DTOs\AttachmentUploadCommand;
use App\Modules\Attachments\Application\UploadMedicalAttachment;
use App\Modules\Attachments\Domain\Enums\AttachmentType;
use App\Modules\ClientPortal\Application\ClientPortalContext;
use App\Modules\ClientPortal\Application\ListClientHealthOverview;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\MedicalProfiles\Application\DTOs\UpdateMedicalProfileCommand;
use App\Modules\MedicalProfiles\Application\UpdateMedicalProfile;
use App\Modules\MedicalProfiles\Domain\Contracts\MedicalEncryptorInterface;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationFeature;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Organizations\Domain\Models\OrganizationFeatureFlag;
use App\Modules\Sessions\Application\CreateSession;
use App\Modules\Sessions\Application\DTOs\CreateSessionCommand;
use App\Modules\Specialists\Domain\Models\Specialist;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

final class PortalHealthProgressTest extends TestCase
{
    use RefreshDatabase;

    public function test_client_health_projection_is_scoped_and_excludes_internal_session_fields(): void
    {
        [$organization, $client, $staff] = $this->fixture();
        $specialist = Specialist::factory()->forOrganization($organization)->create();
        $privateValues = [
            'kinesiotherapyTests' => 'PRIVATE_KINESIOTHERAPY_TESTS_CR10',
            'kinesiotherapyObservations' => 'PRIVATE_KINESIOTHERAPY_OBSERVATIONS_CR10',
            'rootCause' => 'PRIVATE_ROOT_CAUSE_CR10',
            'protocol' => 'PRIVATE_SESSION_PROTOCOL_CR10',
            'aiSummary' => 'PRIVATE_AI_SUMMARY_CR10',
        ];
        app(CreateSession::class)->handle($staff, $client, new CreateSessionCommand(
            specialistId: (int) $specialist->getKey(),
            occurredAt: Carbon::parse('2026-09-10 10:00:00', 'UTC'),
            pain: 'PRIVATE_PAIN_CR10',
            tests: $privateValues['kinesiotherapyTests'],
            observations: $privateValues['kinesiotherapyObservations'],
            rootCauseHypothesis: $privateValues['rootCause'],
            protocol: $privateValues['protocol'],
            result: 'Client-safe session result',
        ));
        $run = AiRun::query()->create([
            'organization_id' => $organization->getKey(),
            'capability' => AiCapability::ClinicalSynthesizer,
            'workflow_key' => ClinicalSynthesizerWorkflow::Summary->value,
            'origin' => AiRunOrigin::User,
            'execution_mode' => AiExecutionMode::Async,
            'client_id' => $client->getKey(),
            'status' => AiRunStatus::Succeeded,
            'human_review_status' => HumanReviewStatus::Accepted,
            'input_references' => [],
            'context_provenance' => [],
            'token_usage' => [],
            'finished_at' => Carbon::parse('2026-09-10 11:00:00', 'UTC'),
        ]);
        $summaryPayload = json_encode(['client_summary' => $privateValues['aiSummary']], JSON_THROW_ON_ERROR);
        AiRunPayload::query()->create([
            'organization_id' => $organization->getKey(),
            'ai_run_id' => $run->getKey(),
            'encryption_key_version' => 1,
            'encrypted_output_payload' => app(MedicalEncryptorInterface::class)->encryptField((int) $organization->getKey(), $summaryPayload, 1),
            'encrypted_output_text' => app(MedicalEncryptorInterface::class)->encryptField((int) $organization->getKey(), $summaryPayload, 1),
        ]);

        app(ClientPortalContext::class)->set($client);
        $overview = app(ListClientHealthOverview::class)->handle();

        self::assertCount(1, $overview['history']);
        self::assertSame('Client-safe session result', $overview['history'][0]['result']);
        self::assertSame(['id', 'clientId', 'occurredAt', 'service', 'specialist', 'result', 'attachments'], array_keys($overview['history'][0]));
        self::assertStringNotContainsString('PRIVATE_', json_encode($overview, JSON_THROW_ON_ERROR));
        self::assertSame((int) $client->getKey(), $overview['history'][0]['clientId']);

        $this->withSession(['client_portal.client_id' => $client->getKey()])
            ->get(route('portal.health'))
            ->assertOk()
            ->assertDontSee('PRIVATE_')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Portal/Health')
                ->missing('health.history.0.pain')
                ->missing('health.history.0.tests')
                ->missing('health.history.0.observations')
                ->missing('health.history.0.rootCauseHypothesis')
                ->missing('health.history.0.protocol')
                ->where('health.courseReport', null));
    }

    public function test_client_health_exposes_only_own_safe_profile_fields_and_private_materials(): void
    {
        Storage::fake('private');
        [$organization, $client, $staff] = $this->fixture();
        $otherClient = Client::factory()->forOrganization($organization)->create();
        app(UpdateMedicalProfile::class)->handle($staff, $client, new UpdateMedicalProfileCommand(
            anamnesis: 'Safe anamnesis',
            complaintsGoals: 'Safe goals',
            operationsInjuries: 'Safe operations',
            medicines: 'Safe medicines',
            supplements: 'Safe supplements',
        ));
        $attachment = app(UploadMedicalAttachment::class)->handle($staff, new AttachmentUploadCommand(
            file: UploadedFile::fake()->createWithContent('report.pdf', "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\n%%EOF"),
            attachmentType: AttachmentType::MedicalReport,
            clientId: (int) $client->getKey(),
        ));

        app(ClientPortalContext::class)->set($client);
        $overview = app(ListClientHealthOverview::class)->handle();

        self::assertTrue($overview['profile']['available']);
        self::assertSame('Safe anamnesis', $overview['profile']['anamnesis']);
        self::assertSame('Safe goals', $overview['profile']['complaintsGoals']);
        self::assertSame('Safe operations', $overview['profile']['operationsInjuries']);
        self::assertArrayNotHasKey('rootCause', $overview['profile']);
        self::assertCount(1, $overview['materials']);
        self::assertStringNotContainsString($attachment->storage_path, $overview['materials'][0]['downloadUrl']);

        $response = $this->withSession(['client_portal.client_id' => $client->getKey()])
            ->get($overview['materials'][0]['downloadUrl'])
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');

        $cacheControl = $response->headers->get('Cache-Control');
        self::assertNotNull($cacheControl);
        self::assertStringContainsString('private', $cacheControl);
        self::assertStringContainsString('no-store', $cacheControl);

        app(ClientPortalContext::class)->set($otherClient);
        $otherOverview = app(ListClientHealthOverview::class)->handle();
        self::assertFalse($otherOverview['profile']['available']);
        self::assertSame([], $otherOverview['materials']);

        $this->withSession(['client_portal.client_id' => $otherClient->getKey()])
            ->get($overview['materials'][0]['downloadUrl'])
            ->assertNotFound();
    }

    public function test_health_route_exposes_the_bounded_progress_projection(): void
    {
        [$organization, $client] = $this->fixture();
        $this->withSession(['client_portal.client_id' => $client->getKey()]);

        $this->get(route('portal.health'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Portal/Health')
                ->has('health.history')
                ->has('health.comparisons')
                ->has('health.postureProgress')
                ->has('health.courseReport'));
    }

    /** @return array{Organization, Client, User} */
    private function fixture(): array
    {
        $organization = Organization::factory()->create(['timezone' => 'UTC']);
        OrganizationFeatureFlag::factory()->forOrganization($organization)->create([
            'feature_key' => OrganizationFeature::ClientRecords->value,
            'enabled' => true,
        ]);
        $staff = User::factory()->forOrganization($organization)->create();
        $client = Client::factory()->forOrganization($organization)->create(['language' => 'en']);
        config()->set('tenancy.default_organization_id', $organization->getKey());
        app(OrganizationContext::class)->set($organization);

        return [$organization, $client, $staff];
    }
}
