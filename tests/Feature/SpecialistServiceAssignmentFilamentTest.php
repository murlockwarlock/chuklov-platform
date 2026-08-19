<?php

namespace Tests\Feature;

use App\Filament\Resources\SpecialistServiceAssignments\Pages\CreateSpecialistServiceAssignment;
use App\Filament\Resources\SpecialistServiceAssignments\SpecialistServiceAssignmentResource;
use App\Models\User;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Scheduling\Application\AssignSpecialistToService;
use App\Modules\Scheduling\Domain\Models\SpecialistServiceAssignment;
use App\Modules\Services\Domain\Models\Service;
use App\Modules\Specialists\Domain\Models\Specialist;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Mockery;
use RuntimeException;
use Tests\TestCase;

final class SpecialistServiceAssignmentFilamentTest extends TestCase
{
    use RefreshDatabase;

    public function test_fresh_assignment_shows_success_and_redirects_to_the_assignment_list(): void
    {
        [$organization, $admin, $specialist, $service] = $this->fixture();

        Livewire::actingAs($admin)
            ->test(CreateSpecialistServiceAssignment::class)
            ->fillForm([
                'specialist_id' => $specialist->getKey(),
                'service_id' => $service->getKey(),
            ])
            ->call('create')
            ->assertHasNoErrors()
            ->assertNotified('Специалист назначен на услугу')
            ->assertRedirect(SpecialistServiceAssignmentResource::getUrl('index'));

        $assignment = SpecialistServiceAssignment::query()->sole();
        self::assertSame($organization->getKey(), $assignment->organization_id);
        self::assertSame($specialist->getKey(), $assignment->specialist_id);
        self::assertSame($service->getKey(), $assignment->service_id);
    }

    public function test_duplicate_assignment_shows_a_visible_russian_error_without_creating_another_record(): void
    {
        [$organization, $admin, $specialist, $service] = $this->fixture();
        app(AssignSpecialistToService::class)->handle($admin, $specialist, $service);

        Livewire::actingAs($admin)
            ->test(CreateSpecialistServiceAssignment::class)
            ->fillForm([
                'specialist_id' => $specialist->getKey(),
                'service_id' => $service->getKey(),
            ])
            ->call('create')
            ->assertNotified('Этот специалист уже оказывает выбранную услугу');

        self::assertSame(1, SpecialistServiceAssignment::query()
            ->where('organization_id', $organization->getKey())
            ->count());
    }

    public function test_unexpected_assignment_exception_is_not_swallowed(): void
    {
        [, $admin, $specialist, $service] = $this->fixture();
        $action = Mockery::mock(AssignSpecialistToService::class);
        $action->shouldReceive('handle')
            ->once()
            ->andThrow(new RuntimeException('Unexpected assignment failure.'));
        $this->app->instance(AssignSpecialistToService::class, $action);

        $this->expectException(RuntimeException::class);

        Livewire::actingAs($admin)
            ->test(CreateSpecialistServiceAssignment::class)
            ->fillForm([
                'specialist_id' => $specialist->getKey(),
                'service_id' => $service->getKey(),
            ])
            ->call('create');
    }

    public function test_cross_organization_specialist_or_service_cannot_be_assigned(): void
    {
        [$organization, $admin, $specialist, $service] = $this->fixture();
        $otherOrganization = Organization::factory()->create();
        $otherSpecialist = Specialist::factory()->forOrganization($otherOrganization)->create();
        $otherService = Service::factory()->forOrganization($otherOrganization)->create();

        foreach ([
            [$otherSpecialist->getKey(), $service->getKey()],
            [$specialist->getKey(), $otherService->getKey()],
        ] as [$specialistId, $serviceId]) {
            Livewire::actingAs($admin)
                ->test(CreateSpecialistServiceAssignment::class)
                ->fillForm([
                    'specialist_id' => $specialistId,
                    'service_id' => $serviceId,
                ])
                ->call('create')
                ->assertHasErrors();
        }

        self::assertSame(0, SpecialistServiceAssignment::query()->count());
    }

    /** @return array{Organization, User, Specialist, Service} */
    private function fixture(): array
    {
        $organization = Organization::factory()->create(['timezone' => 'UTC']);
        $admin = User::factory()->forOrganization($organization)->create();
        $specialist = Specialist::factory()->forOrganization($organization)->create();
        $service = Service::factory()->forOrganization($organization)->create();

        config()->set('tenancy.default_organization_id', $organization->getKey());
        app(OrganizationContext::class)->set($organization);
        $this->actingAs($admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        return [$organization, $admin, $specialist, $service];
    }
}
