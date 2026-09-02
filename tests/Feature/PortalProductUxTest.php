<?php

namespace Tests\Feature;

use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationFeature;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Organizations\Domain\Models\OrganizationFeatureFlag;
use App\Modules\Referrals\Application\EnsureReferralIdentity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class PortalProductUxTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthenticated_root_is_an_entry_surface_and_authenticated_root_is_product_home(): void
    {
        $organization = $this->organizationWithClientRecords();

        $this->get(route('portal.home'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Portal/Entry')
                ->where('portal.authenticated', false)
                ->has('auth.telegramAuthUrl'));

        $client = Client::factory()->forOrganization($organization)->create([
            'full_name' => 'Portal Client',
        ]);
        $this->withSession(['client_portal.client_id' => $client->id]);

        $this->get(route('portal.home'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Portal/Home')
                ->where('portal.authenticated', true)
                ->where('portal.clientName', 'Portal Client')
                ->missing('auth')
                ->missing('onboardingUrl'));
    }

    public function test_authenticated_home_exposes_the_authorized_referrals_destination_and_personal_link(): void
    {
        $organization = $this->organizationWithClientRecords();
        $client = Client::factory()->forOrganization($organization)->create();
        app(OrganizationContext::class)->set($organization);
        $identity = app(EnsureReferralIdentity::class)->handle($client);

        $this->get(route('portal.referrals'))->assertUnauthorized();

        $this->withSession(['client_portal.client_id' => $client->getKey()])
            ->get(route('portal.home'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Portal/Home')
                ->where('portal.urls.referrals', route('portal.referrals')));

        $this->withSession(['client_portal.client_id' => $client->getKey()])
            ->get(route('portal.referrals'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Portal/Referrals')
                ->where('referrals.link', route('portal.referral', ['referralCode' => $identity->public_code]))
                ->where('referrals.registrations', [])
                ->missing('referrals.reward')
                ->missing('referrals.commission'));
    }

    public function test_incomplete_optional_profile_does_not_block_home_or_profile_updates(): void
    {
        $organization = $this->organizationWithClientRecords();
        $client = Client::factory()->forOrganization($organization)->create([
            'full_name' => null,
            'email' => null,
            'phone' => null,
        ]);
        $this->withSession(['client_portal.client_id' => $client->id]);

        $this->get(route('portal.home'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Portal/Home')
                ->where('portal.clientName', null));

        $this->post(route('portal.profile.update'), [
            'phone' => '+77001234567',
        ])->assertRedirect(route('portal.profile'));

        self::assertSame('+77001234567', $client->refresh()->phone);
    }

    public function test_locale_switch_persists_for_anonymous_and_authenticated_portal_sessions(): void
    {
        $organization = $this->organizationWithClientRecords();

        $this->post(route('portal.locale.update'), ['locale' => 'en'])
            ->assertRedirect(route('portal.home'));

        $this->get(route('portal.home'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Portal/Entry')
                ->where('portal.locale', 'en'));

        $client = Client::factory()->forOrganization($organization)->create(['language' => 'ru']);
        $this->withSession(['client_portal.client_id' => $client->id]);

        $this->post(route('portal.locale.update'), ['locale' => 'en'])
            ->assertRedirect(route('portal.home'));

        self::assertSame('en', $client->refresh()->language);
        $this->get(route('portal.home'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Portal/Home')
                ->where('portal.locale', 'en'));
    }

    public function test_client_session_is_organization_scoped_for_the_product_home(): void
    {
        $organization = $this->organizationWithClientRecords();
        $otherOrganization = Organization::factory()->create();
        $otherClient = Client::factory()->forOrganization($otherOrganization)->create();
        config()->set('tenancy.default_organization_id', $organization->id);
        $this->withSession(['client_portal.client_id' => $otherClient->id]);

        $this->get(route('portal.home'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Portal/Entry')
                ->where('portal.authenticated', false));

        $this->get(route('portal.profile'))->assertUnauthorized();
    }

    private function organizationWithClientRecords(): Organization
    {
        $organization = Organization::factory()->create();
        OrganizationFeatureFlag::factory()->forOrganization($organization)->create([
            'feature_key' => OrganizationFeature::ClientRecords->value,
            'enabled' => true,
        ]);
        config()->set('tenancy.default_organization_id', $organization->id);

        return $organization;
    }
}
