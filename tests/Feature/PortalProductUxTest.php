<?php

namespace Tests\Feature;

use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationFeature;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Organizations\Domain\Models\OrganizationFeatureFlag;
use App\Modules\Referrals\Application\EnsureReferralIdentity;
use App\Modules\Referrals\Domain\Enums\ReferralCampaignChannel;
use App\Modules\Referrals\Domain\Models\ReferralCampaignLink;
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
                ->where('healthAction', null)
                ->where('portal.urls.health', route('portal.health'))
                ->where('portal.urls.more', route('portal.more'))
                ->missing('auth')
                ->missing('onboardingUrl'));
    }

    public function test_health_omits_empty_tests_destination_and_secondary_functions_live_under_more(): void
    {
        $organization = $this->organizationWithClientRecords();
        $client = Client::factory()->forOrganization($organization)->create();
        $this->withSession(['client_portal.client_id' => $client->getKey()]);

        $this->get(route('portal.health'))
            ->assertRedirect(route('portal.tracker'));

        $this->get(route('portal.surveys.index'))
            ->assertRedirect(route('portal.health'));

        $this->get(route('portal.more'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
                ->component('Portal/More')
                ->where('portal.urls.referrals', route('portal.referrals')));
    }

    public function test_authenticated_booking_catalog_keeps_the_primary_shell_and_client_actions_are_not_duplicated(): void
    {
        $organization = $this->organizationWithClientRecords();
        $client = Client::factory()->forOrganization($organization)->create();
        $this->withSession(['client_portal.client_id' => $client->getKey()]);

        $this->get(route('portal.services.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
                ->component('Services/Index')
                ->where('portal.authenticated', true)
                ->where('portal.urls.services', route('portal.services.index')));

        $servicesPage = (string) file_get_contents(resource_path('js/Pages/Services/Index.vue'));
        $appShell = (string) file_get_contents(resource_path('js/Components/Portal/AppShell.vue'));
        $shell = (string) file_get_contents(resource_path('js/Components/Portal/MobileBottomNavigation.vue'));
        $home = (string) file_get_contents(resource_path('js/Pages/Portal/Home.vue'));
        $companion = (string) file_get_contents(resource_path('js/Pages/Portal/Companion.vue'));
        $success = (string) file_get_contents(resource_path('js/Components/Portal/BookingSuccess.vue'));
        $health = (string) file_get_contents(resource_path('js/Pages/Portal/Health.vue'));
        $tracker = (string) file_get_contents(resource_path('js/Pages/Portal/Tracker.vue'));
        $partner = (string) file_get_contents(resource_path('js/Pages/Portal/Referrals.vue'));
        $booking = (string) file_get_contents(resource_path('js/Pages/Portal/BookingCreate.vue'));
        $confirmation = (string) file_get_contents(resource_path('js/Components/Portal/BookingConfirmation.vue'));
        $legal = (string) file_get_contents(resource_path('js/Components/Portal/LegalConsentChecklist.vue'));
        $surveyTake = (string) file_get_contents(resource_path('js/Pages/Portal/SurveyTake.vue'));
        $surveyReport = (string) file_get_contents(resource_path('js/Pages/Portal/SurveyReport.vue'));
        $section = (string) file_get_contents(resource_path('js/Pages/Portal/Section.vue'));
        $more = (string) file_get_contents(resource_path('js/Pages/Portal/More.vue'));
        $portalLocale = (string) file_get_contents(resource_path('js/locales/portal.ts'));

        self::assertStringContainsString('active="bookings"', $servicesPage);
        self::assertStringContainsString('return props.portal.urls.services;', $shell);
        self::assertStringContainsString('return props.portal.urls.health;', $appShell);
        self::assertStringContainsString('return props.portal.urls.health;', $shell);
        self::assertStringNotContainsString("t('bookings.title')", $home);
        self::assertStringNotContainsString('home.referrals', $success);
        self::assertStringNotContainsString(':href="props.urls.tracker"', $health);
        self::assertStringNotContainsString('<details', $tracker.$partner);
        self::assertStringContainsString('portal-tabs--three', $tracker);
        self::assertStringContainsString('portal-tabs--segmented', $tracker);
        self::assertStringContainsString('portal-button portal-button--secondary portal-tracker-specialist-link', $tracker);
        self::assertStringContainsString('portal-segmented', $partner);
        self::assertStringContainsString('portal-count', $partner);
        self::assertStringContainsString('portal-referral-registration-row', $partner);
        self::assertStringContainsString('portal-companion__composer-buttons', $companion);
        self::assertStringContainsString('group-required-acceptance', $confirmation);
        self::assertStringContainsString('@update:required-consent', $booking);
        self::assertStringContainsString('@required-change', $confirmation);
        self::assertStringContainsString('legal.requiredAcceptance', $legal);
        self::assertStringContainsString('document.title', $legal);
        self::assertStringContainsString('update:marketingValue', $legal);
        self::assertStringContainsString('role="radiogroup"', $surveyTake);
        self::assertStringContainsString('scrollIntoView', $surveyTake);
        self::assertStringContainsString('preserveScroll: false', $surveyTake);
        self::assertStringNotContainsString('<select', $surveyTake);
        self::assertStringContainsString('portal-report-actions', $surveyReport);
        self::assertStringContainsString('portal-report-metrics', $surveyReport);
        self::assertStringNotContainsString('grid grid-cols-1 gap-3 sm:grid-cols-2', $surveyReport);
        self::assertStringContainsString('active="more"', $section);
        self::assertSame(5, substr_count($more, 'class="portal-list__row"'));
        self::assertStringContainsString("t('more.title')", $more);
        self::assertStringContainsString("t('more.profile')", $more);
        self::assertStringContainsString("t('more.finance')", $more);
        self::assertStringContainsString("t('more.partnership')", $more);
        self::assertStringContainsString("t('more.feedback')", $more);
        self::assertStringNotContainsString("t('more.business')", $more);
        self::assertStringContainsString("'shell.more': 'Кабинет'", $portalLocale);
        self::assertStringContainsString("'shell.companion': 'Чат'", $portalLocale);
        self::assertStringContainsString("'more.title': 'Кабинет'", $portalLocale);
        self::assertStringContainsString("'survey.technicalDetails': 'Все показатели'", $portalLocale);
    }

    public function test_common_client_portal_sources_do_not_expose_raw_translation_keys(): void
    {
        $files = array_merge(
            glob(resource_path('js/Pages/Portal/*.vue')) ?: [],
            glob(resource_path('js/Pages/Services/*.vue')) ?: [],
            glob(resource_path('js/Components/Portal/*.vue')) ?: [],
        );
        $localeSource = (string) file_get_contents(resource_path('js/locales/portal.ts'));
        $knownRawKeys = ['home.referrals'];

        foreach ($files as $file) {
            $source = (string) file_get_contents($file);

            foreach ($knownRawKeys as $key) {
                self::assertStringNotContainsString($key, $source, basename($file));
            }

            preg_match_all("/\\bt\\(\\s*['\"]([^'\"]+)['\"]/", $source, $translationMatches);
            preg_match_all("/\\bportalText\\([^,]+,\\s*['\"]([^'\"]+)['\"]/", $source, $portalTextMatches);
            $keys = array_unique(array_merge($translationMatches[1] ?? [], $portalTextMatches[1] ?? []));

            foreach ($keys as $key) {
                if ($key === '' || str_ends_with($key, '.')) {
                    continue;
                }

                self::assertMatchesRegularExpression(
                    '/[\'\"]'.preg_quote($key, '/').'[\'\"]\\s*:/',
                    $localeSource,
                    basename($file).' references missing '.$key,
                );
            }
        }
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
                ->where('referrals.link', 'https://t.me/chuklov_test_bot?start=ref_'.$identity->public_code)
                ->where('referrals.registrations', [])
                ->missing('referrals.reward')
                ->missing('referrals.commission'));
    }

    public function test_client_can_activate_partner_cabinet_and_create_channel_links(): void
    {
        $organization = $this->organizationWithClientRecords();
        $client = Client::factory()->forOrganization($organization)->create();
        $this->withSession(['client_portal.client_id' => $client->getKey()]);

        $this->get(route('portal.referrals'))
            ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
                ->where('referrals.isPartner', false)
                ->where('referrals.links', []));

        $this->post(route('portal.referrals.activate'))
            ->assertRedirect(route('portal.referrals'));

        $this->post(route('portal.referrals.links.store'), [
            'name' => 'Instagram — шапка профиля',
            'channel' => ReferralCampaignChannel::Instagram->value,
        ])->assertRedirect(route('portal.referrals'));

        $links = ReferralCampaignLink::query()
            ->where('organization_id', $organization->getKey())
            ->where('partner_client_id', $client->getKey())
            ->orderBy('created_at')
            ->get();

        self::assertCount(2, $links);
        self::assertSame('Instagram — шапка профиля', $links[1]->name);
        self::assertSame(ReferralCampaignChannel::Instagram, $links[1]->channel);

        $this->get(route('portal.referrals'))
            ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
                ->where('referrals.isPartner', true)
                ->where('referrals.links.1.name', 'Instagram — шапка профиля')
                ->where('referrals.links.1.channel', 'Instagram'));

        $this->get(route('portal.referral', ['referralCode' => $links[1]->public_token]))
            ->assertRedirect('https://t.me/chuklov_test_bot?start=ref_'.$links[1]->public_token);

        self::assertDatabaseMissing('referral_link_visits', ['campaign_link_id' => $links[1]->getKey()]);
    }

    public function test_direct_payout_post_from_a_non_partner_is_rejected_at_the_backend_boundary(): void
    {
        $organization = $this->organizationWithClientRecords();
        $client = Client::factory()->forOrganization($organization)->create();
        $this->withSession(['client_portal.client_id' => $client->getKey()]);

        $this->post(route('portal.referrals.payouts.store'), [
            'amount' => '1.00',
            'currency' => 'USD',
            'idempotency_key' => 'non-partner-direct-post',
        ])->assertInvalid('partner');

        self::assertDatabaseCount('referral_payout_requests', 0);
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
        config()->set('portal.telegram.bot_username', 'chuklov_test_bot');

        return $organization;
    }
}
