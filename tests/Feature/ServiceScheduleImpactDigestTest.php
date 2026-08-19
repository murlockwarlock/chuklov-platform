<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationFeature;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Organizations\Domain\Models\OrganizationFeatureFlag;
use App\Modules\Scheduling\Application\AssignSpecialistToService;
use App\Modules\Scheduling\Application\CreateBooking;
use App\Modules\Scheduling\Application\ScheduleMutationImpactCalculator;
use App\Modules\Scheduling\Application\SetSpecialistWorkingHours;
use App\Modules\Scheduling\Domain\Enums\VisitFormat;
use App\Modules\Services\Application\UpdateService;
use App\Modules\Services\Domain\Contracts\ServiceMediaStorageInterface;
use App\Modules\Services\Domain\Models\Service;
use App\Modules\Specialists\Domain\Models\Specialist;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class ServiceScheduleImpactDigestTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow(CarbonImmutable::create(2026, 3, 27, 12, 0, 0, 'UTC'));
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_service_impact_digest_uses_only_scheduling_intent(): void
    {
        [$organization, , $client, $specialist, $service] = $this->fixture();
        $this->createBooking($client, $specialist, $service, 'service-impact-digest');
        $schedulingChange = [
            'duration_minutes' => 45,
            'buffer_minutes' => 15,
            'formats' => ['office', 'online'],
            'is_active' => true,
            'catalog_type' => 'service',
        ];
        $managedPathA = "services/{$organization->getKey()}/aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa.jpg";
        $managedPathB = "services/{$organization->getKey()}/bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb.jpg";
        $calculator = app(ScheduleMutationImpactCalculator::class);

        $managedImpactA = $calculator->forServiceChange($service, [
            ...$schedulingChange,
            'image_path' => $managedPathA,
            'price_minor' => 100,
            'price_currency' => 'USD',
            'name' => 'First service label',
        ]);
        $managedImpactB = $calculator->forServiceChange($service, [
            ...$schedulingChange,
            'image_path' => $managedPathB,
            'price_minor' => 200,
            'price_currency' => 'KZT',
            'name' => 'Second service label',
        ]);
        $externalImpact = $calculator->forServiceChange($service, [
            ...$schedulingChange,
            'external_image_url' => 'https://cdn.example.test/service.jpg',
            'description_en' => 'A different media-only description.',
        ]);
        $changedSchedulingImpact = $calculator->forServiceChange($service, [
            ...$schedulingChange,
            'duration_minutes' => 30,
            'image_path' => $managedPathA,
        ]);

        self::assertSame($managedImpactA->digest, $managedImpactB->digest);
        self::assertSame($managedImpactA->digest, $externalImpact->digest);
        self::assertNotSame($managedImpactA->digest, $changedSchedulingImpact->digest);
    }

    public function test_uploading_a_new_media_path_does_not_invalidate_service_schedule_acknowledgement(): void
    {
        [$organization, $admin, $client, $specialist, $service] = $this->fixture();
        $this->createBooking($client, $specialist, $service, 'service-upload-impact');
        $pathA = "services/{$organization->getKey()}/cccccccc-cccc-4ccc-8ccc-cccccccccccc.jpg";
        $pathB = "services/{$organization->getKey()}/dddddddd-dddd-4ddd-8ddd-dddddddddddd.jpg";
        $media = \Mockery::mock(ServiceMediaStorageInterface::class);
        $media->shouldReceive('store')
            ->twice()
            ->with($organization->getKey(), \Mockery::type(UploadedFile::class))
            ->andReturn($pathA, $pathB);
        $media->shouldReceive('deleteManaged')
            ->once()
            ->with($organization->getKey(), $pathA);
        app()->instance(ServiceMediaStorageInterface::class, $media);

        $firstDigest = null;

        try {
            app(UpdateService::class)->handle(
                actor: $admin,
                service: $service,
                name: $this->serviceUpdateAttributes($service, UploadedFile::fake()->image('first.jpg'), 45),
            );
            self::fail('A scheduling change with an affected booking was accepted without acknowledgement.');
        } catch (ValidationException $exception) {
            $firstDigest = (string) $exception->errors()['schedule_impact_digest'][0];
        }

        $updated = app(UpdateService::class)->handle(
            actor: $admin,
            service: $service,
            name: $this->serviceUpdateAttributes($service, UploadedFile::fake()->image('second.jpg'), 45),
            acknowledgeImpact: true,
            acknowledgedImpactDigest: $firstDigest,
        );

        self::assertSame($pathB, $updated->fresh()->image_path);
        self::assertSame(45, $updated->fresh()->duration_minutes);
    }

    /** @return array<string, mixed> */
    private function serviceUpdateAttributes(Service $service, UploadedFile $file, int $durationMinutes): array
    {
        return [
            'name' => $service->name,
            'summary' => $service->summary,
            'is_active' => true,
            'catalog_type' => $service->catalogItemType()->value,
            'duration_minutes' => $durationMinutes,
            'buffer_minutes' => $service->buffer_minutes,
            'formats' => $service->formats,
            'service_image' => $file,
        ];
    }

    /** @return array{0: Organization, 1: User, 2: Client, 3: Specialist, 4: Service} */
    private function fixture(): array
    {
        $organization = Organization::factory()->create(['timezone' => 'UTC']);
        $admin = User::factory()->forOrganization($organization)->create();
        $client = Client::factory()->forOrganization($organization)->create(['timezone' => 'UTC']);
        $specialist = Specialist::factory()->forOrganization($organization)->create(['timezone' => 'UTC']);
        $service = Service::factory()->forOrganization($organization)->create([
            'duration_minutes' => 60,
            'buffer_minutes' => 15,
            'formats' => ['office', 'online'],
        ]);
        config()->set('tenancy.default_organization_id', $organization->getKey());
        app(OrganizationContext::class)->set($organization);
        OrganizationFeatureFlag::factory()->forOrganization($organization)->create([
            'feature_key' => OrganizationFeature::ServiceCatalog->value,
            'enabled' => true,
        ]);
        app(AssignSpecialistToService::class)->handle($admin, $specialist, $service);
        app(SetSpecialistWorkingHours::class)->handle($admin, $specialist, [[
            'weekday' => 1,
            'start_time' => '09:00',
            'end_time' => '17:00',
        ]]);

        return [$organization, $admin, $client, $specialist, $service];
    }

    private function createBooking(Client $client, Specialist $specialist, Service $service, string $key): void
    {
        app(CreateBooking::class)->handle(
            actor: $client,
            client: $client,
            specialist: $specialist,
            service: $service,
            startsAt: CarbonImmutable::create(2026, 4, 6, 9, 0, 0, 'UTC'),
            format: VisitFormat::Office,
            idempotencyKey: $key,
        );
    }
}
