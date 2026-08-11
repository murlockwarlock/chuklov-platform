<?php

namespace Tests\Feature;

use App\Filament\Resources\Services\Pages\CreateService;
use App\Filament\Resources\Services\Pages\EditService;
use App\Models\User;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Services\Domain\Models\Service;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Livewire\Livewire;
use Tests\TestCase;

class FilamentServiceSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_cannot_open_another_organizations_service_edit_url(): void
    {
        $organization = Organization::factory()->create();
        $otherOrganization = Organization::factory()->create();
        $admin = User::factory()->for($organization)->create();
        $ownService = Service::factory()->for($organization)->create();
        $otherService = Service::factory()->for($otherOrganization)->create();

        $this->actingAs($admin)
            ->get(route('filament.admin.resources.services.edit', ['record' => $ownService]))
            ->assertOk();

        $this->actingAs($admin)
            ->get(route('filament.admin.resources.services.edit', ['record' => $otherService]))
            ->assertNotFound();
    }

    public function test_non_admin_and_organizationless_users_are_rejected(): void
    {
        $organization = Organization::factory()->create();
        $nonAdmin = User::factory()->for($organization)->create(['is_admin' => false]);
        $organizationlessAdmin = User::factory()->create([
            'organization_id' => null,
            'is_admin' => true,
        ]);

        $this->actingAs($nonAdmin)->get('/admin')->assertForbidden();
        $this->actingAs($organizationlessAdmin)->get('/admin')->assertForbidden();
    }

    public function test_filament_create_and_update_use_the_application_path_and_publish_to_portal(): void
    {
        $organization = Organization::factory()->create();
        $otherOrganization = Organization::factory()->create();
        $admin = User::factory()->for($organization)->create();
        $this->resolveFilamentContext($admin, $organization);

        Livewire::actingAs($admin)
            ->test(CreateService::class)
            ->fillForm([
                'name' => 'Filament Foundation Service',
                'summary' => 'Created through the CRM adapter.',
                'is_active' => true,
            ])
            ->call('create')
            ->assertHasNoErrors()
            ->assertRedirect();

        $service = Service::query()->sole();
        self::assertSame($organization->id, $service->organization_id);

        Livewire::actingAs($admin)
            ->test(EditService::class, ['record' => $service->getRouteKey()])
            ->fillForm([
                'name' => 'Updated Filament Foundation Service',
                'summary' => 'Updated through the CRM adapter.',
                'is_active' => true,
            ])
            ->call('save')
            ->assertHasNoErrors();

        Service::factory()->for($organization)->inactive()->create(['name' => 'Inactive Service']);
        Service::factory()->for($otherOrganization)->create(['name' => 'Cross Organization Service']);

        $this->actingAs($admin)
            ->get(route('portal.services.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Services/Index')
                ->has('services', 1)
                ->where('services.0.name', 'Updated Filament Foundation Service'));
    }

    private function resolveFilamentContext(User $admin, Organization $organization): void
    {
        $this->actingAs($admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        app(OrganizationContext::class)->set($organization);
    }
}
