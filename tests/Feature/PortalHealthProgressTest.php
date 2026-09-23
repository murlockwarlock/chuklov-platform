<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Attachments\Application\DTOs\AttachmentUploadCommand;
use App\Modules\Attachments\Application\UploadMedicalAttachment;
use App\Modules\Attachments\Domain\Enums\AttachmentType;
use App\Modules\ClientPortal\Application\ClientPortalContext;
use App\Modules\ClientPortal\Application\ListClientHealthOverview;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\MedicalProfiles\Application\DTOs\UpdateMedicalProfileCommand;
use App\Modules\MedicalProfiles\Application\UpdateMedicalProfile;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Models\Organization;
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
        app(CreateSession::class)->handle($staff, $client, new CreateSessionCommand(
            specialistId: (int) $specialist->getKey(),
            occurredAt: Carbon::parse('2026-09-10 10:00:00', 'UTC'),
            pain: 'Internal pain note',
            rootCauseHypothesis: 'Internal hypothesis',
            protocol: 'Internal protocol',
            result: 'Client-safe session result',
        ));

        app(ClientPortalContext::class)->set($client);
        $overview = app(ListClientHealthOverview::class)->handle();

        self::assertCount(1, $overview['history']);
        self::assertSame('Client-safe session result', $overview['history'][0]['result']);
        self::assertArrayNotHasKey('pain', $overview['history'][0]);
        self::assertArrayNotHasKey('rootCauseHypothesis', $overview['history'][0]);
        self::assertArrayNotHasKey('protocol', $overview['history'][0]);
        self::assertSame((int) $client->getKey(), $overview['history'][0]['clientId']);
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
        $staff = User::factory()->forOrganization($organization)->create();
        $client = Client::factory()->forOrganization($organization)->create(['language' => 'en']);
        config()->set('tenancy.default_organization_id', $organization->getKey());
        app(OrganizationContext::class)->set($organization);

        return [$organization, $client, $staff];
    }
}
