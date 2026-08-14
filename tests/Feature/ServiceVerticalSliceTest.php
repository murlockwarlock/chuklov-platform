<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationFeature;
use App\Modules\Organizations\Domain\Enums\OrganizationRole;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Organizations\Domain\Models\OrganizationFeatureFlag;
use App\Modules\Services\Application\CreateService;
use App\Modules\Services\Application\UpdateService;
use App\Modules\Services\Domain\Models\Service;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class ServiceVerticalSliceTest extends TestCase
{
    use RefreshDatabase;

    public function test_application_created_service_is_visible_in_the_portal(): void
    {
        $organization = Organization::factory()->create();
        $admin = User::factory()->forOrganization($organization, OrganizationRole::Administrator)->create();
        $this->enableServiceCatalog($organization);
        config()->set('tenancy.default_organization_id', $organization->id);
        app(OrganizationContext::class)->set($organization);

        app(CreateService::class)->handle($admin, 'Foundation Service', 'Architecture proof.', true);

        $this->get(route('portal.services.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Services/Index')
                ->has('services', 1)
                ->where('services.0.name', 'Foundation Service'));
    }

    public function test_application_service_creation_requires_the_service_catalog_feature(): void
    {
        $organization = Organization::factory()->create();
        $admin = User::factory()->forOrganization($organization, OrganizationRole::Administrator)->create();
        app(OrganizationContext::class)->set($organization);

        $this->expectException(AuthorizationException::class);

        app(CreateService::class)->handle($admin, 'Blocked Service', 'Feature is disabled.', true);
    }

    public function test_application_service_update_requires_the_service_catalog_feature(): void
    {
        $organization = Organization::factory()->create();
        $admin = User::factory()->forOrganization($organization, OrganizationRole::Administrator)->create();
        $service = Service::factory()->forOrganization($organization)->create(['name' => 'Original Service']);
        app(OrganizationContext::class)->set($organization);

        $this->expectException(AuthorizationException::class);

        app(UpdateService::class)->handle($admin, $service, 'Blocked Update', 'Feature is disabled.', true);
    }

    public function test_request_input_cannot_override_the_organization_boundary(): void
    {
        $organization = Organization::factory()->create();
        $other = Organization::factory()->create();
        $this->enableServiceCatalog($organization);
        config()->set('tenancy.default_organization_id', $organization->id);
        Service::factory()->forOrganization($organization)->create(['name' => 'Allowed']);
        Service::factory()->forOrganization($other)->create(['name' => 'Forbidden']);

        $this->get(route('portal.services.index', ['organization_id' => $other->id]))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('services', 1)
                ->where('services.0.name', 'Allowed'));
    }

    public function test_inactive_and_cross_organization_services_are_hidden(): void
    {
        $organization = Organization::factory()->create();
        $other = Organization::factory()->create();
        $this->enableServiceCatalog($organization);
        config()->set('tenancy.default_organization_id', $organization->id);
        Service::factory()->forOrganization($organization)->inactive()->create();
        Service::factory()->forOrganization($other)->create();

        $this->get(route('portal.services.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->has('services', 0));
    }

    public function test_disabled_service_catalog_hides_existing_services_from_portal_and_legacy_onboarding_route(): void
    {
        $organization = Organization::factory()->create();
        $client = Client::factory()
            ->forOrganization($organization)
            ->create();
        Service::factory()->forOrganization($organization)->create([
            'name' => 'Existing service after entitlement removal',
        ]);
        config()->set('tenancy.default_organization_id', $organization->id);
        $this->withSession(['client_portal.client_id' => $client->id]);

        $this->get(route('portal.services.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Services/Index')
                ->has('services', 0));

        $this->get(route('portal.onboarding'))
            ->assertRedirect(route('portal.profile'));
    }

    private function enableServiceCatalog(Organization $organization): void
    {
        OrganizationFeatureFlag::factory()->forOrganization($organization)->create([
            'feature_key' => OrganizationFeature::ServiceCatalog->value,
            'enabled' => true,
        ]);
    }
}
