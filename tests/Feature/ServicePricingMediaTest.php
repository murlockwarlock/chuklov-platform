<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationFeature;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Organizations\Domain\Models\OrganizationFeatureFlag;
use App\Modules\Services\Application\CreateService;
use App\Modules\Services\Application\ServiceImageUrlResolver;
use App\Modules\Services\Application\UpdateService;
use App\Modules\Services\Domain\Contracts\ServiceMediaStorageInterface;
use App\Modules\Services\Domain\Models\Service;
use App\Modules\Services\Infrastructure\Storage\FilesystemServiceMediaStorage;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Database\QueryException;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

final class ServicePricingMediaTest extends TestCase
{
    use RefreshDatabase;

    private const DISK = 'service-media-test';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('service_media.disk', self::DISK);
        config()->set('service_media.max_bytes', 5_242_880);
        Storage::fake(self::DISK);
    }

    public function test_managed_delete_failure_is_detectable_on_a_non_throwing_disk(): void
    {
        $organizationId = 41;
        $path = 'services/41/44444444-4444-4444-8444-444444444444.jpg';
        $disk = \Mockery::mock(FilesystemAdapter::class);
        $disk->shouldReceive('exists')->once()->with($path)->andReturnTrue();
        $disk->shouldReceive('delete')->once()->with($path)->andReturnFalse();
        $disk->shouldReceive('missing')->once()->with($path)->andReturnFalse();
        Storage::shouldReceive('disk')->once()->with(self::DISK)->andReturn($disk);

        $this->expectException(RuntimeException::class);

        app(FilesystemServiceMediaStorage::class)->deleteManaged($organizationId, $path);
    }

    public function test_valid_upload_uses_an_organization_namespaced_generated_path(): void
    {
        [$organization, $admin] = $this->organizationAndAdmin();

        $service = app(CreateService::class)->handle($admin, [
            'name' => 'Photo consultation',
            'summary' => 'Service with a managed image.',
            'service_image' => UploadedFile::fake()->image('client-name.jpg', 640, 480),
        ]);

        self::assertMatchesRegularExpression(
            '/^services\/'.$organization->id.'\/[0-9a-f-]{36}\.jpg$/i',
            (string) $service->image_path,
        );
        self::assertNull($service->external_image_url);
        Storage::disk(self::DISK)->assertExists($service->image_path);
    }

    public function test_invalid_mime_is_rejected_even_when_the_filename_looks_like_an_image(): void
    {
        [$organization, $admin] = $this->organizationAndAdmin();

        $this->expectException(ValidationException::class);

        app(CreateService::class)->handle($admin, [
            'name' => 'Invalid upload',
            'summary' => 'The MIME type is not an image.',
            'service_image' => UploadedFile::fake()->createWithContent('looks-like-a.jpg', 'plain text'),
        ]);
    }

    public function test_oversized_upload_is_rejected(): void
    {
        [, $admin] = $this->organizationAndAdmin();
        config()->set('service_media.max_bytes', 100);

        $this->expectException(ValidationException::class);

        app(CreateService::class)->handle($admin, [
            'name' => 'Oversized upload',
            'summary' => 'The upload is too large.',
            'service_image' => UploadedFile::fake()->createWithContent('large.jpg', str_repeat('x', 101)),
        ]);
    }

    public function test_svg_upload_is_rejected(): void
    {
        [, $admin] = $this->organizationAndAdmin();

        $this->expectException(ValidationException::class);

        app(CreateService::class)->handle($admin, [
            'name' => 'SVG upload',
            'summary' => 'SVG is not a supported service image.',
            'service_image' => UploadedFile::fake()->createWithContent('icon.svg', '<svg xmlns="http://www.w3.org/2000/svg"></svg>'),
        ]);
    }

    public function test_https_external_image_url_is_stored_without_a_managed_path(): void
    {
        [, $admin] = $this->organizationAndAdmin();
        $url = 'https://cdn.example.test/services/consultation.jpg';

        $service = app(CreateService::class)->handle($admin, [
            'name' => 'External image service',
            'summary' => 'Service with an external image.',
            'external_image_url' => $url,
        ]);

        self::assertNull($service->image_path);
        self::assertSame($url, $service->external_image_url);
    }

    public function test_non_https_external_image_url_is_rejected(): void
    {
        [, $admin] = $this->organizationAndAdmin();

        $this->expectException(InvalidArgumentException::class);

        app(CreateService::class)->handle($admin, [
            'name' => 'Insecure external image',
            'summary' => 'HTTP must not be accepted.',
            'external_image_url' => 'http://cdn.example.test/service.jpg',
        ]);
    }

    public function test_legacy_image_path_keeps_the_existing_asset_url_behavior(): void
    {
        [$organization] = $this->organizationAndAdmin();
        $service = Service::factory()->forOrganization($organization)->create([
            'image_path' => 'portal-assets/services/legacy.jpg',
        ]);

        self::assertSame(
            asset('portal-assets/services/legacy.jpg'),
            app(ServiceImageUrlResolver::class)->resolve($service),
        );
    }

    public function test_managed_image_path_resolves_through_the_configured_storage_disk(): void
    {
        [$organization] = $this->organizationAndAdmin();
        $path = "services/{$organization->id}/11111111-1111-4111-8111-111111111111.jpg";
        Storage::disk(self::DISK)->put($path, 'managed image');
        $service = Service::factory()->forOrganization($organization)->create(['image_path' => $path]);

        self::assertSame(
            Storage::disk(self::DISK)->url($path),
            app(ServiceImageUrlResolver::class)->resolve($service),
        );
    }

    public function test_external_image_resolution_does_not_fetch_the_remote_url(): void
    {
        [$organization] = $this->organizationAndAdmin();
        $url = 'https://cdn.example.test/services/external.jpg';
        $service = Service::factory()->forOrganization($organization)->create([
            'external_image_url' => $url,
        ]);
        Http::preventStrayRequests();

        self::assertSame($url, app(ServiceImageUrlResolver::class)->resolve($service));
    }

    public function test_replacing_a_managed_image_deletes_the_old_object_after_successful_persistence(): void
    {
        [$organization, $admin] = $this->organizationAndAdmin();
        $service = app(CreateService::class)->handle($admin, [
            'name' => 'Replaceable image service',
            'summary' => 'The first image is managed.',
            'service_image' => UploadedFile::fake()->image('first.jpg'),
        ]);
        $oldPath = (string) $service->image_path;

        $updated = app(UpdateService::class)->handle($admin, $service, [
            'name' => $service->name,
            'summary' => $service->summary,
            'is_active' => true,
            'service_image' => UploadedFile::fake()->image('second.jpg'),
        ]);
        $newPath = (string) $updated->image_path;
        $this->commitOuterTransactionAndResetDatabase();

        self::assertNotSame($oldPath, $newPath);
        Storage::disk(self::DISK)->assertMissing($oldPath);
        Storage::disk(self::DISK)->assertExists($newPath);
    }

    public function test_removing_a_managed_image_deletes_only_that_managed_object(): void
    {
        [$organization, $admin] = $this->organizationAndAdmin();
        $service = app(CreateService::class)->handle($admin, [
            'name' => 'Removable image service',
            'summary' => 'The image can be removed.',
            'service_image' => UploadedFile::fake()->image('remove.jpg'),
        ]);
        $path = (string) $service->image_path;

        $updated = app(UpdateService::class)->handle($admin, $service, [
            'name' => $service->name,
            'summary' => $service->summary,
            'is_active' => true,
            'remove_image' => true,
        ]);
        $this->commitOuterTransactionAndResetDatabase();

        self::assertNull($updated->image_path);
        self::assertNull($updated->external_image_url);
        Storage::disk(self::DISK)->assertMissing($path);
    }

    public function test_removing_an_external_image_clears_the_current_url(): void
    {
        [, $admin] = $this->organizationAndAdmin();
        $url = 'https://cdn.example.test/services/remove.jpg';
        $service = app(CreateService::class)->handle($admin, [
            'name' => 'Removable external image service',
            'summary' => 'The external image can be removed.',
            'external_image_url' => $url,
        ]);

        $updated = app(UpdateService::class)->handle($admin, $service, [
            'name' => $service->name,
            'summary' => $service->summary,
            'is_active' => true,
            'external_image_url' => $url,
            'remove_image' => true,
        ]);

        self::assertNull($updated->image_path);
        self::assertNull($updated->external_image_url);
    }

    public function test_legacy_asset_is_never_deleted_when_the_service_image_is_removed(): void
    {
        [$organization, $admin] = $this->organizationAndAdmin();
        $legacyPath = 'portal-assets/services/legacy.jpg';
        Storage::disk(self::DISK)->put($legacyPath, 'legacy asset');
        $service = Service::factory()->forOrganization($organization)->create([
            'image_path' => $legacyPath,
        ]);

        app(UpdateService::class)->handle($admin, $service, [
            'name' => $service->name,
            'summary' => $service->summary,
            'is_active' => true,
            'remove_image' => true,
        ]);

        Storage::disk(self::DISK)->assertExists($legacyPath);
    }

    public function test_failed_persistence_keeps_the_old_image_and_cleans_the_new_orphan(): void
    {
        [$organization, $admin] = $this->organizationAndAdmin();
        $service = app(CreateService::class)->handle($admin, [
            'name' => 'Original image service',
            'summary' => 'The old image must survive a failed update.',
            'service_image' => UploadedFile::fake()->image('original.jpg'),
        ]);
        $oldPath = (string) $service->image_path;
        Service::factory()->forOrganization($organization)->create(['name' => 'Conflicting service']);

        $this->expectException(QueryException::class);

        try {
            app(UpdateService::class)->handle($admin, $service, [
                'name' => 'Conflicting service',
                'summary' => $service->summary,
                'is_active' => true,
                'service_image' => UploadedFile::fake()->image('orphan.jpg'),
            ]);
        } finally {
            Storage::disk(self::DISK)->assertExists($oldPath);
            self::assertSame([$oldPath], Storage::disk(self::DISK)->allFiles());
        }
    }

    public function test_failed_create_rethrows_the_persistence_exception_and_reports_cleanup_failure(): void
    {
        [$organization, $admin] = $this->organizationAndAdmin();
        $name = 'Conflicting create service';
        Service::factory()->forOrganization($organization)->create(['name' => $name]);
        $storedPath = "services/{$organization->id}/55555555-5555-4555-8555-555555555555.jpg";
        $cleanupException = new RuntimeException('create cleanup failed');
        $media = \Mockery::mock(ServiceMediaStorageInterface::class);
        $media->shouldReceive('store')
            ->once()
            ->with($organization->getKey(), \Mockery::type(UploadedFile::class))
            ->andReturn($storedPath);
        $media->shouldReceive('deleteManaged')
            ->once()
            ->with($organization->getKey(), $storedPath)
            ->andThrow($cleanupException);
        app()->instance(ServiceMediaStorageInterface::class, $media);
        $this->fakeExceptionReporting();

        try {
            app(CreateService::class)->handle($admin, [
                'name' => $name,
                'summary' => 'The create must fail.',
                'service_image' => UploadedFile::fake()->image('create-orphan.jpg'),
            ]);

            self::fail('The duplicate service name must fail persistence.');
        } catch (QueryException $exception) {
            self::assertInstanceOf(QueryException::class, $exception);
        }

        Exceptions::assertReported(fn (RuntimeException $reported): bool => $reported === $cleanupException);
    }

    public function test_failed_update_rethrows_the_persistence_exception_and_reports_cleanup_failure(): void
    {
        [$organization, $admin] = $this->organizationAndAdmin();
        $service = app(CreateService::class)->handle($admin, [
            'name' => 'Original update service',
            'summary' => 'The old image must survive.',
            'service_image' => UploadedFile::fake()->image('update-original.jpg'),
        ]);
        $oldPath = (string) $service->image_path;
        $conflictingName = 'Conflicting update service';
        Service::factory()->forOrganization($organization)->create(['name' => $conflictingName]);
        $storedPath = "services/{$organization->id}/66666666-6666-4666-8666-666666666666.jpg";
        $cleanupException = new RuntimeException('update cleanup failed');
        $media = \Mockery::mock(ServiceMediaStorageInterface::class);
        $media->shouldReceive('store')
            ->once()
            ->with($organization->getKey(), \Mockery::type(UploadedFile::class))
            ->andReturn($storedPath);
        $media->shouldReceive('deleteManaged')
            ->once()
            ->with($organization->getKey(), $storedPath)
            ->andThrow($cleanupException);
        app()->instance(ServiceMediaStorageInterface::class, $media);
        $this->fakeExceptionReporting();

        try {
            app(UpdateService::class)->handle($admin, $service, [
                'name' => $conflictingName,
                'summary' => $service->summary,
                'is_active' => true,
                'service_image' => UploadedFile::fake()->image('update-orphan.jpg'),
            ]);

            self::fail('The duplicate service name must fail persistence.');
        } catch (QueryException $exception) {
            self::assertInstanceOf(QueryException::class, $exception);
        }

        Storage::disk(self::DISK)->assertExists($oldPath);
        Exceptions::assertReported(fn (RuntimeException $reported): bool => $reported === $cleanupException);
    }

    public function test_post_commit_cleanup_failure_is_reported_without_failing_the_successful_update(): void
    {
        [$organization, $admin] = $this->organizationAndAdmin();
        $service = app(CreateService::class)->handle($admin, [
            'name' => 'Post-commit cleanup service',
            'summary' => 'The database update must remain successful.',
            'service_image' => UploadedFile::fake()->image('post-commit.jpg'),
        ]);
        $oldPath = (string) $service->image_path;
        $cleanupException = new RuntimeException('post-commit cleanup failed');
        $media = \Mockery::mock(ServiceMediaStorageInterface::class);
        $media->shouldReceive('isManagedPath')
            ->once()
            ->with($organization->getKey(), $oldPath)
            ->andReturnTrue();
        $media->shouldReceive('deleteManaged')
            ->once()
            ->with($organization->getKey(), $oldPath)
            ->andThrow($cleanupException);
        app()->instance(ServiceMediaStorageInterface::class, $media);
        $this->fakeExceptionReporting();

        $updated = app(UpdateService::class)->handle($admin, $service, [
            'name' => $service->name,
            'summary' => $service->summary,
            'is_active' => true,
            'remove_image' => true,
        ]);

        self::assertNull($updated->image_path);
        $this->commitOuterTransactionAndResetDatabase();

        Storage::disk(self::DISK)->assertExists($oldPath);
        Exceptions::assertReported(fn (RuntimeException $reported): bool => $reported === $cleanupException);
    }

    public function test_managed_media_cannot_be_resolved_or_deleted_across_organizations(): void
    {
        [$organizationA] = $this->organizationAndAdmin();
        $organizationB = Organization::factory()->create();
        $path = "services/{$organizationA->id}/22222222-2222-4222-8222-222222222222.png";
        Storage::disk(self::DISK)->put($path, 'organization A image');
        $serviceB = Service::factory()->forOrganization($organizationB)->create(['image_path' => $path]);
        $media = app(ServiceMediaStorageInterface::class);

        self::assertNull(app(ServiceImageUrlResolver::class)->resolve($serviceB));

        $media->deleteManaged($organizationB->id, $path);

        Storage::disk(self::DISK)->assertExists($path);
    }

    public function test_managed_media_path_cannot_be_reused_by_another_organization(): void
    {
        [$organizationA] = $this->organizationAndAdmin();
        $organizationB = Organization::factory()->create();
        $path = "services/{$organizationA->id}/33333333-3333-4333-8333-333333333333.jpg";
        [, $adminB] = $this->organizationAndAdminFor($organizationB);

        $this->expectException(ValidationException::class);

        app(CreateService::class)->handle($adminB, [
            'name' => 'Cross organization image reference',
            'summary' => 'A managed path must stay with its organization.',
            'image_path' => $path,
        ]);
    }

    /** @return array{0: Organization, 1: User} */
    private function organizationAndAdmin(): array
    {
        $organization = Organization::factory()->create();

        return $this->organizationAndAdminFor($organization);
    }

    /** @return array{0: Organization, 1: User} */
    private function organizationAndAdminFor(Organization $organization): array
    {
        $admin = User::factory()->forOrganization($organization)->create();
        OrganizationFeatureFlag::factory()->forOrganization($organization)->create([
            'feature_key' => OrganizationFeature::ServiceCatalog->value,
            'enabled' => true,
        ]);
        config()->set('tenancy.default_organization_id', $organization->id);
        app(OrganizationContext::class)->set($organization);

        return [$organization, $admin];
    }

    private function commitOuterTransactionAndResetDatabase(): void
    {
        DB::commit();

        DB::statement('PRAGMA foreign_keys = OFF');
        foreach (Schema::getTableListing() as $table) {
            if (in_array($table, ['migrations', 'sqlite_sequence'], true)) {
                continue;
            }

            DB::table($table)->delete();
        }
        DB::statement('PRAGMA foreign_keys = ON');

        DB::beginTransaction();
    }

    private function fakeExceptionReporting(): void
    {
        $fake = Exceptions::fake();
        app()->instance(ExceptionHandler::class, $fake);
    }
}
