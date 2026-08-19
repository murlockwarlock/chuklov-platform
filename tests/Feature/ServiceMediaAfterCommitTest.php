<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationFeature;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Organizations\Domain\Models\OrganizationFeatureFlag;
use App\Modules\Services\Application\CreateService;
use App\Modules\Services\Application\UpdateService;
use App\Modules\Services\Domain\Contracts\ServiceMediaStorageInterface;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

final class ServiceMediaAfterCommitTest extends TestCase
{
    use DatabaseTruncation;

    private const DISK = 'service-media-test';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('service_media.disk', self::DISK);
        config()->set('service_media.max_bytes', 5_242_880);
        Storage::fake(self::DISK);
    }

    protected function beforeTruncatingDatabase(): void
    {
        if (config('database.connections.'.config('database.default').'.database') === ':memory:') {
            RefreshDatabaseState::$migrated = false;
        }
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

        self::assertNull($updated->image_path);
        self::assertNull($updated->external_image_url);
        Storage::disk(self::DISK)->assertMissing($path);
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
        Storage::disk(self::DISK)->assertExists($oldPath);
        Exceptions::assertReported(fn (RuntimeException $reported): bool => $reported === $cleanupException);
    }

    /** @return array{0: Organization, 1: User} */
    private function organizationAndAdmin(): array
    {
        $organization = Organization::factory()->create();
        $admin = User::factory()->forOrganization($organization)->create();
        OrganizationFeatureFlag::factory()->forOrganization($organization)->create([
            'feature_key' => OrganizationFeature::ServiceCatalog->value,
            'enabled' => true,
        ]);
        config()->set('tenancy.default_organization_id', $organization->id);
        app(OrganizationContext::class)->set($organization);

        return [$organization, $admin];
    }

    private function fakeExceptionReporting(): void
    {
        $fake = Exceptions::fake();
        app()->instance(ExceptionHandler::class, $fake);
    }
}
