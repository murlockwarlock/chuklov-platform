<?php

namespace Tests\Feature;

use App\Modules\ClientPortal\Application\ClientPortalContext;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationFeature;
use App\Modules\Organizations\Domain\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use LogicException;
use Tests\TestCase;

final class PortalHistoryProtectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_changing_clients_rotates_history_and_returns_only_the_current_client(): void
    {
        $organization = $this->organization();
        $first = Client::factory()->forOrganization($organization)->create(['full_name' => 'Synthetic first client']);
        $second = Client::factory()->forOrganization($organization)->create(['full_name' => 'Synthetic second client']);
        $this->withSession(['client_portal.client_id' => $first->id]);
        $firstPage = $this->get(route('portal.profile'))->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('portal.clientName', $first->full_name));
        self::assertTrue($firstPage->viewData('page')['encryptHistory']);
        self::assertTrue($firstPage->viewData('page')['clearHistory']);
        $sameClientPage = $this->get(route('portal.profile'))->assertOk()->viewData('page');
        self::assertTrue($sameClientPage['encryptHistory']);
        self::assertArrayNotHasKey('clearHistory', $sameClientPage);
        $this->withSession(['client_portal.client_id' => $second->id]);
        $secondPage = $this->get(route('portal.profile'))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('portal.clientName', $second->full_name));
        self::assertTrue($secondPage->viewData('page')['encryptHistory']);
        self::assertTrue($secondPage->viewData('page')['clearHistory']);
        self::assertSame($second->id, app(ClientPortalContext::class)->id());
    }

    public function test_expired_or_foreign_identity_clears_context_and_old_history(): void
    {
        $organization = $this->organization();
        $client = Client::factory()->forOrganization($organization)->create();
        $this->withSession(['client_portal.client_id' => $client->id]);
        $this->get(route('portal.home'))->assertOk();
        $foreign = Client::factory()->forOrganization(Organization::factory()->create())->create();
        $this->withSession(['client_portal.client_id' => $foreign->id]);
        $guestPage = $this->get(route('portal.home'))->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertSessionMissing('client_portal.client_id')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Portal/Entry')->where('portal.authenticated', false));
        self::assertTrue($guestPage->viewData('page')['encryptHistory']);
        self::assertTrue($guestPage->viewData('page')['clearHistory']);
        $this->get(route('portal.profile'))->assertUnauthorized();
        $this->expectException(LogicException::class);
        app(ClientPortalContext::class)->client();
    }

    private function organization(): Organization
    {
        $organization = Organization::factory()->create();
        app(OrganizationContext::class)->set($organization);
        config(['tenancy.default_organization_id' => $organization->id]);
        $organization->featureFlags()->create(['feature_key' => OrganizationFeature::ClientRecords->value, 'enabled' => true]);

        return $organization;
    }
}
