<?php

namespace Tests\Feature;

use App\Http\Middleware\RequireClientPortalSession;
use App\Models\User;
use App\Modules\ClientPortal\Application\ClientPortalContext;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Domain\Enums\OrganizationRole;
use App\Modules\Organizations\Domain\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SystemProofSecurityBoundaryTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_session_protected_portal_route_denies_a_guest_without_business_writes(): void
    {
        $organization = Organization::factory()->create();
        config()->set('tenancy.default_organization_id', $organization->getKey());
        $before = $this->tableCounts();
        $checked = 0;

        foreach (app('router')->getRoutes() as $route) {
            if (! in_array(RequireClientPortalSession::class, $route->gatherMiddleware(), true)) {
                continue;
            }

            app()->forgetInstance(ClientPortalContext::class);
            $uri = $this->concreteUri($route);
            $response = $this->call($route->methods()[0], $uri, [
                'organization_id' => $organization->getKey(),
                'client_id' => 1,
            ]);
            self::assertSame(401, $response->status(), $route->getName());
            $checked++;
        }

        self::assertGreaterThan(40, $checked);
        self::assertSame($before, $this->tableCounts());
    }

    public function test_foreign_client_session_cannot_enter_any_session_protected_portal_route(): void
    {
        $organization = Organization::factory()->create();
        $foreignOrganization = Organization::factory()->create();
        $foreignClient = Client::factory()->forOrganization($foreignOrganization)->create();
        config()->set('tenancy.default_organization_id', $organization->getKey());
        $before = $this->tableCounts();
        $checked = 0;

        foreach (app('router')->getRoutes() as $route) {
            if (! in_array(RequireClientPortalSession::class, $route->gatherMiddleware(), true)) {
                continue;
            }

            app()->forgetInstance(ClientPortalContext::class);
            $this->withSession(['client_portal.client_id' => $foreignClient->getKey()])
                ->call($route->methods()[0], $this->concreteUri($route), [
                    'organization_id' => $foreignOrganization->getKey(),
                    'client_id' => $foreignClient->getKey(),
                ])
                ->assertUnauthorized()
                ->assertSessionMissing('client_portal.client_id');
            $checked++;
        }

        self::assertGreaterThan(40, $checked);
        self::assertSame($before, $this->tableCounts());
    }

    public function test_staff_cannot_open_any_crm_resource_or_standalone_page_by_direct_url(): void
    {
        $organization = Organization::factory()->create();
        $staff = User::factory()->forOrganization($organization, OrganizationRole::Staff)->create();
        config()->set('tenancy.default_organization_id', $organization->getKey());
        $before = $this->tableCounts();
        $checked = 0;

        foreach (app('router')->getRoutes() as $route) {
            $name = $route->getName() ?? '';

            if (! str_starts_with($name, 'filament.admin.resources.') && ! str_starts_with($name, 'filament.admin.pages.')) {
                continue;
            }

            $response = $this->actingAs($staff)->get($this->concreteUri($route));
            self::assertContains($response->status(), [302, 403], $name);
            if ($response->status() === 302) {
                $response->assertRedirect(route('filament.admin.auth.login'));
                self::assertGuest();
            }
            $checked++;
        }

        self::assertGreaterThan(80, $checked);
        self::assertSame($before, $this->tableCounts());
    }

    private function concreteUri(Route $route): string
    {
        $uri = str_replace('{outcome}', 'success', $route->uri());

        return '/'.preg_replace('/\{[^}]+\}/', '1', $uri);
    }

    private function tableCounts(): array
    {
        $counts = [];

        foreach (Schema::getTables() as $table) {
            $name = $table['name'];
            if (in_array($name, ['sessions', 'cache', 'cache_locks'], true)) {
                continue;
            }

            $counts[$name] = DB::table($name)->count();
        }

        ksort($counts);

        return $counts;
    }
}
