<?php

namespace Tests\Feature;

use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Services\Application\CreateService;
use App\Modules\Services\Domain\Models\Service;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class ServiceVerticalSliceTest extends TestCase
{
    use RefreshDatabase;

    public function test_application_created_service_is_visible_in_the_portal(): void
    {
        $organization = Organization::factory()->create();
        config()->set('tenancy.default_organization_id', $organization->id);
        app(OrganizationContext::class)->set($organization);

        app(CreateService::class)->handle('Foundation Service', 'Architecture proof.', true);

        $this->get(route('portal.services.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Services/Index')
                ->has('services', 1)
                ->where('services.0.name', 'Foundation Service'));
    }

    public function test_request_input_cannot_override_the_organization_boundary(): void
    {
        $organization = Organization::factory()->create();
        $other = Organization::factory()->create();
        config()->set('tenancy.default_organization_id', $organization->id);
        Service::factory()->for($organization)->create(['name' => 'Allowed']);
        Service::factory()->for($other)->create(['name' => 'Forbidden']);

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
        config()->set('tenancy.default_organization_id', $organization->id);
        Service::factory()->for($organization)->inactive()->create();
        Service::factory()->for($other)->create();

        $this->get(route('portal.services.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->has('services', 0));
    }
}
