<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\B2B\Application\SaveB2bZoomConfiguration;
use App\Modules\B2B\Domain\Models\B2bLead;
use App\Modules\B2B\Domain\Models\B2bSalesCall;
use App\Modules\B2B\Jobs\ProcessB2bProviderSyncEvent;
use App\Modules\Broadcasts\Application\SetClientB2bSpecialistAnswer;
use App\Modules\Broadcasts\Domain\Enums\B2bSpecialistAnswer;
use App\Modules\Channels\Application\ResolveTelegramMiniAppEntry;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Identity\Domain\Models\ClientChannelIdentity;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Application\SetOrganizationSetting;
use App\Modules\Organizations\Domain\Enums\OrganizationFeature;
use App\Modules\Organizations\Domain\Enums\OrganizationSettingKey;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Organizations\Domain\Models\OrganizationFeatureFlag;
use App\Modules\Referrals\Application\EnsureReferralIdentity;
use App\Modules\Referrals\Domain\Models\ClientReferralIdentity;
use App\Modules\Scheduling\Application\AssignSpecialistToService;
use App\Modules\Scheduling\Application\SetSpecialistWorkingHours;
use App\Modules\Services\Domain\Models\Service;
use App\Modules\Specialists\Domain\Models\Specialist;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Testing\FakeNutgram;
use Tests\Support\TelegramInitData;
use Tests\TestCase;

final class TelegramMiniAppLaunchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow(CarbonImmutable::create(2026, 8, 27, 10, 0, 0, 'UTC'));
        config()->set('portal.telegram.portal_url', 'https://mini.example.test');
        Queue::fake();
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_unauthenticated_b2b_launch_authenticates_before_the_protected_lead_submission(): void
    {
        $fixture = $this->b2bFixture();
        $identity = ClientChannelIdentity::factory()->forClient($fixture['client'])->create([
            'external_id' => '820001',
        ]);
        $this->useTelegramToken();

        $this->get(route('portal.telegram.launch', ['entry' => 'b2b']))
            ->assertRedirect(route('portal.home', ['telegram_entry' => 'b2b'], false));

        $this->get(route('portal.home', ['telegram_entry' => 'b2b']))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
                ->component('Portal/Entry')
                ->where('auth.telegramLaunchEntry', 'b2b'));

        $sessionIdBeforeAuthentication = session()->getId();
        $this->post(route('portal.telegram.auth'), [
            'initData' => TelegramInitData::make(820001, now()->timestamp),
            'launchEntry' => 'b2b',
        ])->assertRedirect(route('portal.b2b', [], false));

        self::assertNotSame($sessionIdBeforeAuthentication, session()->getId());
        self::assertSame($fixture['client']->getKey(), (int) session('client_portal.client_id'));
        self::assertNotNull($identity->refresh()->verified_at);

        $this->get(route('portal.b2b'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
                ->component('Portal/B2b')
                ->where('authenticated', true));

        $this->post(route('portal.b2b.submit'), [
            'specialist_id' => $fixture['specialist']->getKey(),
            'starts_at' => '2026-08-31T15:00:00+00:00',
            'submission_key' => 'telegram-mini-app-b2b-lead',
        ])->assertRedirect(route('portal.b2b'));

        self::assertSame(1, B2bLead::query()->where('client_id', $fixture['client']->getKey())->count());
        self::assertSame(1, B2bSalesCall::query()->where('client_id', $fixture['client']->getKey())->count());
        Queue::assertPushedOn(config('b2b.queue'), ProcessB2bProviderSyncEvent::class);
    }

    public function test_authenticated_launches_redirect_directly_to_the_allowlisted_internal_destinations(): void
    {
        $organization = $this->organizationWithClientRecords();
        $client = Client::factory()->forOrganization($organization)->create();

        foreach ([
            'portal' => route('portal.home', [], false),
            'author' => route('portal.section', ['section' => 'author'], false),
            'method' => route('portal.section', ['section' => 'method'], false),
            'b2b' => route('portal.b2b', [], false),
            'partner' => route('portal.section', ['section' => 'partner'], false),
        ] as $key => $destination) {
            $this->withSession(['client_portal.client_id' => $client->getKey()])
                ->get(route('portal.telegram.launch', ['entry' => $key]))
                ->assertRedirect($destination);
        }
    }

    public function test_unauthenticated_protected_launches_keep_the_allowlisted_entry_for_the_existing_mini_app_auth_flow(): void
    {
        $this->organizationWithClientRecords();

        foreach (['portal', 'b2b'] as $key) {
            $this->get(route('portal.telegram.launch', ['entry' => $key]))
                ->assertRedirect(route('portal.home', ['telegram_entry' => $key], false));

            $this->get(route('portal.home', ['telegram_entry' => $key]))
                ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
                    ->component('Portal/Entry')
                    ->where('auth.telegramLaunchEntry', $key));
        }
    }

    public function test_unauthenticated_public_launches_remain_directly_readable_inside_the_mini_app(): void
    {
        $this->organizationWithClientRecords();

        foreach ([
            'author' => route('portal.section', ['section' => 'author'], false),
            'method' => route('portal.section', ['section' => 'method'], false),
            'partner' => route('portal.section', ['section' => 'partner'], false),
        ] as $key => $destination) {
            $this->get(route('portal.telegram.launch', ['entry' => $key]))
                ->assertRedirect($destination);
        }
    }

    public function test_unknown_and_manipulated_destinations_fail_closed(): void
    {
        $this->organizationWithClientRecords();

        $this->get('/portal/telegram/launch/unknown')->assertNotFound();
        $this->get('/portal/telegram/launch/..%2F..%2Fevil')->assertNotFound();

        foreach ([
            'https://evil.example.test',
            '//evil.example.test',
            'https%3A%2F%2Fevil.example.test',
        ] as $value) {
            $this->get(route('portal.telegram.launch', ['entry' => 'b2b']).'?return_to='.$value.'&destination=/portal/profile')
                ->assertRedirect(route('portal.home', ['telegram_entry' => 'b2b'], false))
                ->assertSessionMissing('client_portal.client_id');
        }

        self::assertSame(
            route('portal.b2b', [], false),
            app(ResolveTelegramMiniAppEntry::class)->destination('b2b'),
        );
    }

    public function test_invalid_init_data_keeps_the_bounded_destination_and_localized_retry_state(): void
    {
        $this->organizationWithClientRecords();
        $this->useTelegramToken();
        $this->withSession(['portal.locale' => 'en']);

        $this->post(route('portal.telegram.auth'), [
            'initData' => TelegramInitData::make(820003, now()->timestamp, token: 'wrong-token'),
            'launchEntry' => 'b2b',
        ])->assertRedirect(route('portal.home', ['telegram_entry' => 'b2b'], false));

        $this->get(route('portal.home', ['telegram_entry' => 'b2b']))
            ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
                ->component('Portal/Entry')
                ->where('auth.telegramLaunchEntry', 'b2b')
                ->where('auth.telegramAuthError', 'Telegram sign-in failed. Close the app and open it again.'));
    }

    public function test_authenticated_launch_continuation_ignores_manipulated_destinations(): void
    {
        $this->organizationWithClientRecords();
        $this->useTelegramToken();

        foreach (['https://evil.example.test', '//evil.example.test', '/portal/profile'] as $index => $destination) {
            $this->post(route('portal.telegram.auth'), [
                'initData' => TelegramInitData::make(820010 + $index, now()->addSeconds($index)->timestamp),
                'launchEntry' => $destination,
            ])->assertRedirect(route('portal.home'));
        }
    }

    public function test_telegram_start_parameter_remains_attribution_evidence_separate_from_the_launch_entry(): void
    {
        $organization = $this->organizationWithClientRecords();
        $referrer = Client::factory()->forOrganization($organization)->create();
        $referralIdentity = app(EnsureReferralIdentity::class)->handle($referrer);
        $this->useTelegramToken();

        $this->get(route('portal.telegram.launch', ['entry' => 'portal']))->assertRedirect();
        $this->get(route('portal.home', ['telegram_entry' => 'portal']))->assertOk();
        $this->post(route('portal.telegram.auth'), [
            'initData' => TelegramInitData::make(
                userId: 820002,
                authDate: now()->timestamp,
                startParameter: $referralIdentity->public_code,
            ),
            'launchEntry' => 'portal',
        ])->assertRedirect(route('portal.home'));

        $client = Client::query()->whereHas('channelIdentities', fn ($query) => $query->where('external_id', '820002'))->sole();
        $relationship = ClientReferralIdentity::query()
            ->where('organization_id', $organization->getKey())
            ->where('client_id', $referrer->getKey())
            ->firstOrFail();

        self::assertSame($referralIdentity->getKey(), $relationship->getKey());
        self::assertDatabaseHas('referral_relationships', [
            'organization_id' => $organization->getKey(),
            'referrer_client_id' => $referrer->getKey(),
            'referred_client_id' => $client->getKey(),
        ]);
    }

    /** @return array{organization: Organization, client: Client, specialist: Specialist, service: Service} */
    private function b2bFixture(): array
    {
        $organization = $this->organizationWithClientRecords();
        $admin = User::factory()->forOrganization($organization)->create();
        $client = Client::factory()->forOrganization($organization)->create(['timezone' => 'UTC']);
        $specialist = Specialist::factory()->forOrganization($organization)->create(['timezone' => 'UTC']);
        $service = Service::factory()->forOrganization($organization)->create([
            'duration_minutes' => 60,
            'buffer_minutes' => 0,
            'formats' => ['office', 'online'],
        ]);
        $this->setOrganization($organization);
        app(SaveB2bZoomConfiguration::class)->handle(
            actor: $admin,
            accountId: 'telegram-account',
            clientId: 'telegram-client',
            clientSecret: 'telegram-secret',
            hostUserId: 'telegram-host',
            enabled: true,
        );
        OrganizationFeatureFlag::factory()->forOrganization($organization)->create([
            'feature_key' => OrganizationFeature::ServiceCatalog->value,
            'enabled' => true,
        ]);
        app(SetOrganizationSetting::class)->handle($admin, OrganizationSettingKey::B2bSalesCallDurationMinutes, 60);
        app(SetOrganizationSetting::class)->handle($admin, OrganizationSettingKey::B2bZoomHostLicensed, true);
        app(AssignSpecialistToService::class)->handle($admin, $specialist, $service);
        app(SetSpecialistWorkingHours::class)->handle($admin, $specialist, [[
            'weekday' => 1,
            'start_time' => '09:00',
            'end_time' => '19:00',
        ]]);
        app(SetClientB2bSpecialistAnswer::class)->handle(
            actor: $client,
            client: $client,
            answer: B2bSpecialistAnswer::Yes,
            source: 'portal',
        );

        return compact('organization', 'client', 'specialist', 'service');
    }

    private function organizationWithClientRecords(): Organization
    {
        $organization = Organization::factory()->create();
        OrganizationFeatureFlag::factory()->forOrganization($organization)->create([
            'feature_key' => OrganizationFeature::ClientRecords->value,
            'enabled' => true,
        ]);
        $this->setOrganization($organization);

        return $organization;
    }

    private function setOrganization(Organization $organization): void
    {
        config()->set('tenancy.default_organization_id', $organization->getKey());
        app(OrganizationContext::class)->set($organization);
    }

    private function useTelegramToken(): void
    {
        config()->set('nutgram.token', FakeNutgram::TOKEN);
        app()->forgetInstance(Nutgram::class);
    }
}
