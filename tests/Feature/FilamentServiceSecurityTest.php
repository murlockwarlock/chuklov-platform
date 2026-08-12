<?php

namespace Tests\Feature;

use App\Filament\Resources\Services\Pages\CreateService;
use App\Filament\Resources\Services\Pages\EditService;
use App\Models\User;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationFeature;
use App\Modules\Organizations\Domain\Enums\OrganizationRole;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Organizations\Domain\Models\OrganizationFeatureFlag;
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
        $admin = User::factory()->forOrganization($organization)->create();
        $this->setServerOrganization($organization);
        $this->enableServiceCatalog($organization);
        $ownService = Service::factory()->forOrganization($organization)->create();
        $otherService = Service::factory()->forOrganization($otherOrganization)->create();

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
        $nonAdmin = User::factory()->forOrganization($organization, OrganizationRole::Staff)->create();
        $organizationlessAdmin = User::factory()->create();
        $this->setServerOrganization($organization);

        $this->actingAs($nonAdmin)->get('/admin')->assertForbidden();
        $this->actingAs($organizationlessAdmin)->get('/admin')->assertForbidden();
    }

    public function test_filament_create_and_update_use_the_application_path_and_publish_to_portal(): void
    {
        $organization = Organization::factory()->create();
        $otherOrganization = Organization::factory()->create();
        $admin = User::factory()->forOrganization($organization)->create();
        $this->enableServiceCatalog($organization);
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

        Service::factory()->forOrganization($organization)->inactive()->create(['name' => 'Inactive Service']);
        Service::factory()->forOrganization($otherOrganization)->create(['name' => 'Cross Organization Service']);

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
        $this->setServerOrganization($organization);
        $this->actingAs($admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        app(OrganizationContext::class)->set($organization);
    }

    private function setServerOrganization(Organization $organization): void
    {
        config()->set('tenancy.default_organization_id', $organization->id);
    }

    private function enableServiceCatalog(Organization $organization): void
    {
        OrganizationFeatureFlag::factory()->forOrganization($organization)->create([
            'feature_key' => OrganizationFeature::ServiceCatalog->value,
            'enabled' => true,
        ]);
    }
}
