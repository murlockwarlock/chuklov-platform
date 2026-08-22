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
use Filament\Forms\Components\Select;
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

    public function test_assignment_selects_have_bounded_tenant_scoped_initial_and_search_results(): void
    {
        [$organization, $admin] = $this->fixture();
        $targetSpecialist = Specialist::factory()->forOrganization($organization)->create([
            'display_name' => 'A Target Specialist',
        ]);
        $targetService = Service::factory()->forOrganization($organization)->create([
            'name' => 'A Target Service',
        ]);
        $otherOrganization = Organization::factory()->create();
        $foreignSpecialist = Specialist::factory()->forOrganization($otherOrganization)->create([
            'display_name' => 'A Target Specialist',
        ]);
        $foreignService = Service::factory()->forOrganization($otherOrganization)->create([
            'name' => 'A Target Service',
        ]);

        for ($index = 0; $index < 55; $index++) {
            Specialist::factory()->forOrganization($organization)->create([
                'display_name' => 'Z Specialist '.str_pad((string) $index, 2, '0', STR_PAD_LEFT),
            ]);
            Service::factory()->forOrganization($organization)->create([
                'name' => 'Z Service '.str_pad((string) $index, 2, '0', STR_PAD_LEFT),
            ]);
        }

        $component = Livewire::actingAs($admin)->test(CreateSpecialistServiceAssignment::class);

        foreach ([
            'specialist_id' => [$targetSpecialist, $foreignSpecialist, 'A Target Specialist'],
            'service_id' => [$targetService, $foreignService, 'A Target Service'],
        ] as $fieldName => [$target, $foreign, $targetLabel]) {
            $select = $component->instance()->getSchemaComponent('form.'.$fieldName);
            self::assertInstanceOf(Select::class, $select);
            self::assertTrue($select->isPreloaded());
            self::assertSame(50, $select->getOptionsLimit());

            $options = $select->getOptions();
            self::assertCount(50, $options);
            self::assertSame($targetLabel, $options[$target->getKey()] ?? null);
            self::assertArrayNotHasKey($foreign->getKey(), $options);

            $searchResults = $select->getSearchResults('A Target');
            self::assertSame($targetLabel, $searchResults[$target->getKey()] ?? null);
            self::assertArrayNotHasKey($foreign->getKey(), $searchResults);
        }

        $component->fillForm([
            'specialist_id' => $targetSpecialist->getKey(),
            'service_id' => $targetService->getKey(),
        ]);

        foreach ([
            'specialist_id' => [$targetSpecialist, 'A Target Specialist'],
            'service_id' => [$targetService, 'A Target Service'],
        ] as $fieldName => [$target, $targetLabel]) {
            $select = $component->instance()->getSchemaComponent('form.'.$fieldName);
            self::assertInstanceOf(Select::class, $select);
            self::assertSame((string) $target->getKey(), (string) $select->getState());
            self::assertSame($targetLabel, $select->getOptionLabel());
        }
    }

    public function test_assignment_selects_use_filament_dynamic_search_beyond_initial_options(): void
    {
        [$organization, $admin] = $this->fixture();

        for ($index = 1; $index <= 55; $index++) {
            Specialist::factory()->forOrganization($organization)->create([
                'display_name' => 'A Specialist '.str_pad((string) $index, 2, '0', STR_PAD_LEFT),
            ]);
            Service::factory()->forOrganization($organization)->create([
                'name' => 'A Service '.str_pad((string) $index, 2, '0', STR_PAD_LEFT),
            ]);
        }

        $targetSpecialist = Specialist::factory()->forOrganization($organization)->create([
            'display_name' => 'Z Target Specialist',
        ]);
        $targetService = Service::factory()->forOrganization($organization)->create([
            'name' => 'Z Target Service',
        ]);
        $otherOrganization = Organization::factory()->create();
        $foreignSpecialist = Specialist::factory()->forOrganization($otherOrganization)->create([
            'display_name' => 'Z Target Specialist',
        ]);
        $foreignService = Service::factory()->forOrganization($otherOrganization)->create([
            'name' => 'Z Target Service',
        ]);

        $component = Livewire::actingAs($admin)->test(CreateSpecialistServiceAssignment::class);

        foreach ([
            'specialist_id' => [$targetSpecialist, $foreignSpecialist, 'Z Target Specialist'],
            'service_id' => [$targetService, $foreignService, 'Z Target Service'],
        ] as $fieldName => [$target, $foreign, $targetLabel]) {
            $select = $component->instance()->getSchemaComponent('form.'.$fieldName);
            self::assertInstanceOf(Select::class, $select);
            self::assertTrue($select->hasDynamicOptions());
            self::assertTrue($select->hasDynamicSearchResults());

            $initialOptions = $select->getOptionsForJs();
            self::assertCount(50, $initialOptions);
            self::assertFalse(collect($initialOptions)->contains('value', (string) $target->getKey()));

            $searchResults = $component->instance()->callSchemaComponentMethod(
                'form.'.$fieldName,
                'getSearchResultsForJs',
                ['search' => $targetLabel],
            );

            self::assertSame([
                ['label' => $targetLabel, 'value' => (string) $target->getKey(), 'isDisabled' => false],
            ], $searchResults);
            self::assertFalse(collect($searchResults)->contains('value', (string) $foreign->getKey()));
        }

        $html = $component->html();
        self::assertStringContainsString('hasDynamicOptions: true', $html);
        self::assertStringContainsString('hasDynamicSearchResults: true', $html);
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
