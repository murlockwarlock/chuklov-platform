<?php

namespace Tests\Feature\B2b;

use App\Filament\Pages\SchedulingConfiguration;
use App\Models\User;
use App\Modules\Analytics\Application\Data\DashboardPeriod;
use App\Modules\Analytics\Application\SchedulingAnalytics;
use App\Modules\B2B\Application\B2bProviderLeaseManager;
use App\Modules\B2B\Application\B2bProviderMutationGuard;
use App\Modules\B2B\Application\CancelB2bSalesCall;
use App\Modules\B2B\Application\GetB2bSalesCallDuration;
use App\Modules\B2B\Application\GetB2bSalesCallHostLaunchUrl;
use App\Modules\B2B\Application\GetB2bSalesCallReadiness;
use App\Modules\B2B\Application\GetB2bZoomConfiguration;
use App\Modules\B2B\Application\GetPortalB2bRequest;
use App\Modules\B2B\Application\ListB2bLeadsForCrm;
use App\Modules\B2B\Application\ListB2bSalesCallAvailability;
use App\Modules\B2B\Application\MarkB2bSalesCallProviderReconciliationRequired;
use App\Modules\B2B\Application\RecordB2bProviderSyncEvent;
use App\Modules\B2B\Application\RecreateB2bSalesCallMeeting;
use App\Modules\B2B\Application\RescheduleB2bSalesCall;
use App\Modules\B2B\Application\RetryB2bSalesCallProvider;
use App\Modules\B2B\Application\SaveB2bZoomConfiguration;
use App\Modules\B2B\Application\SetB2bSalesCallMeetingMode;
use App\Modules\B2B\Application\SubmitB2bLead;
use App\Modules\B2B\Application\SyncB2bSalesCallProvider;
use App\Modules\B2B\Application\UpdateB2bLeadStatus;
use App\Modules\B2B\Domain\Contracts\VideoMeetingProvider;
use App\Modules\B2B\Domain\Enums\B2bLeadSource;
use App\Modules\B2B\Domain\Enums\B2bLeadStatus;
use App\Modules\B2B\Domain\Enums\B2bSalesCallStatus;
use App\Modules\B2B\Domain\Enums\VideoMeetingMode;
use App\Modules\B2B\Domain\Enums\VideoMeetingOperation;
use App\Modules\B2B\Domain\Enums\VideoMeetingSyncStatus;
use App\Modules\B2B\Domain\Models\B2bLead;
use App\Modules\B2B\Domain\Models\B2bSalesCall;
use App\Modules\B2B\Domain\ValueObjects\ProviderAccountAffinity;
use App\Modules\B2B\Domain\ValueObjects\ProviderOperationDeadline;
use App\Modules\B2B\Domain\ValueObjects\ProviderOperationLease;
use App\Modules\B2B\Domain\ValueObjects\VideoMeetingIdentity;
use App\Modules\B2B\Domain\ValueObjects\VideoMeetingRequest;
use App\Modules\B2B\Domain\ValueObjects\VideoMeetingResult;
use App\Modules\B2B\Infrastructure\Video\VideoMeetingException;
use App\Modules\B2B\Jobs\ProcessB2bProviderSyncEvent;
use App\Modules\Broadcasts\Application\BroadcastSegmentQuery;
use App\Modules\Broadcasts\Application\BroadcastSegmentSummary;
use App\Modules\Broadcasts\Application\SetClientB2bSpecialistAnswer;
use App\Modules\Broadcasts\Domain\Enums\B2bSpecialistAnswer;
use App\Modules\Broadcasts\Domain\Models\BroadcastClientProfile;
use App\Modules\Channels\Application\GetTelegramMenu;
use App\Modules\ClientPortal\Application\ClientPortalContext;
use App\Modules\ClientPortal\Application\GetClientOnboarding;
use App\Modules\ClientPortal\Application\GetClientProfile;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Integration\Domain\Enums\IntegrationEventType;
use App\Modules\Integration\Domain\Models\IntegrationEvent;
use App\Modules\Organizations\Application\ClearOrganizationSetting;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Application\SetOrganizationSetting;
use App\Modules\Organizations\Domain\Enums\OrganizationFeature;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use App\Modules\Organizations\Domain\Enums\OrganizationRole;
use App\Modules\Organizations\Domain\Enums\OrganizationSettingKey;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Organizations\Domain\Models\OrganizationFeatureFlag;
use App\Modules\Scheduling\Application\AssignSpecialistToService;
use App\Modules\Scheduling\Application\CreateBooking;
use App\Modules\Scheduling\Application\SetSpecialistWorkingHours;
use App\Modules\Scheduling\Domain\Enums\VisitFormat;
use App\Modules\Scheduling\Domain\Models\Booking;
use App\Modules\Scheduling\Domain\Models\UnavailablePeriod;
use App\Modules\Security\Domain\Enums\CredentialStatus;
use App\Modules\Security\Domain\Models\AuditEvent;
use App\Modules\Security\Domain\Models\OrganizationCredential;
use App\Modules\Services\Domain\Models\Service;
use App\Modules\Specialists\Domain\Models\Specialist;
use Carbon\CarbonImmutable;
use Closure;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia;
use Livewire\Livewire;
use Mockery;
use Tests\TestCase;

final class B2bLeadFunnelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow(CarbonImmutable::create(2026, 8, 27, 10, 0, 0, 'UTC'));
        Queue::fake();
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_onboarding_yes_is_persisted_and_hydrated_on_revisit(): void
    {
        $fixture = $this->fixture(false);
        $client = $fixture['client'];

        $this->withSession(['client_portal.client_id' => $client->getKey()])
            ->post(route('portal.onboarding.update', ['stage' => 'contacts']), [
                'b2b_specialist_answer' => 'yes',
            ])
            ->assertRedirect(route('portal.onboarding'));

        $this->withSession(['client_portal.client_id' => $client->getKey()])
            ->get(route('portal.profile'))
            ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
                ->component('Portal/Profile')
                ->where('b2bSpecialistAnswer', 'yes'));

        $this->setPortalClient($client);
        $first = app(GetClientOnboarding::class)->handle();
        $second = app(GetClientOnboarding::class)->handle();
        $profile = app(GetClientProfile::class)->handle();

        self::assertSame('yes', $first['b2bSpecialistAnswer']);
        self::assertSame('yes', $second['b2bSpecialistAnswer']);
        self::assertSame('yes', $profile['b2bSpecialistAnswer']);
        self::assertSame(1, BroadcastClientProfile::query()->where('client_id', $client->getKey())->count());
        self::assertSame('yes', BroadcastClientProfile::query()->where('client_id', $client->getKey())->firstOrFail()->getRawOriginal('b2b_specialist_answer'));
    }

    public function test_onboarding_no_does_not_create_a_lead_and_submission_is_rejected(): void
    {
        $fixture = $this->fixture(false);
        $client = $fixture['client'];

        $this->withSession(['client_portal.client_id' => $client->getKey()])
            ->post(route('portal.onboarding.update', ['stage' => 'contacts']), [
                'b2b_specialist_answer' => 'no',
            ])
            ->assertRedirect(route('portal.onboarding'));

        self::assertSame(0, B2bLead::query()->count());

        try {
            $this->submit($fixture, 'no-answer');
            self::fail('A client who answered no was allowed to submit a B2B lead.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('b2b_specialist_answer', $exception->errors());
        }

        self::assertSame(0, B2bLead::query()->count());
    }

    public function test_changing_classification_is_audited_without_rewriting_attribution(): void
    {
        $fixture = $this->fixture();
        $client = $fixture['client'];
        $capturedAt = now();
        DB::table('client_attributions')->insert([
            'organization_id' => $fixture['organization']->getKey(),
            'client_id' => $client->getKey(),
            'source_type' => 'manual',
            'source' => 'owner-campaign',
            'capture_channel' => 'portal',
            'captured_at' => $capturedAt,
            'accepted_at' => $capturedAt,
            'created_at' => $capturedAt,
            'updated_at' => $capturedAt,
        ]);

        app(SetClientB2bSpecialistAnswer::class)->handle(
            actor: $fixture['admin'],
            client: $client,
            answer: B2bSpecialistAnswer::No,
            source: 'crm',
        );

        $audit = AuditEvent::query()
            ->where('organization_id', $fixture['organization']->getKey())
            ->where('action', 'b2b.client.specialist_answer.updated')
            ->latest('id')
            ->firstOrFail();

        self::assertSame('yes', $audit->metadata['old_answer']);
        self::assertSame('no', $audit->metadata['answer']);
        self::assertSame('owner-campaign', DB::table('client_attributions')->where('client_id', $client->getKey())->value('source'));
        self::assertSame('no', BroadcastClientProfile::query()->where('client_id', $client->getKey())->firstOrFail()->getRawOriginal('b2b_specialist_answer'));
    }

    public function test_yes_answer_is_available_as_a_typed_broadcast_segment_with_human_label(): void
    {
        $fixture = $this->fixture();

        $matchingClientIds = app(BroadcastSegmentQuery::class)->build(
            $fixture['organization']->getKey(),
            [['key' => 'b2b_specialist_answer', 'operator' => 'equals', 'value' => 'yes']],
        )->pluck('id')->all();
        $summary = app(BroadcastSegmentSummary::class)->make([
            ['key' => 'b2b_specialist_answer', 'operator' => 'equals', 'value' => 'yes'],
        ]);

        self::assertSame([$fixture['client']->getKey()], $matchingClientIds);
        self::assertSame('B2B-сегмент специалиста · #Массажист_B2B', $summary);
    }

    public function test_direct_portal_and_telegram_cta_are_available_with_managed_copy(): void
    {
        $fixture = $this->fixture(false);
        $client = $fixture['client'];

        $this->withSession(['client_portal.client_id' => $client->getKey()])
            ->get(route('portal.b2b'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
                ->component('Portal/B2b')
                ->where('authenticated', true)
                ->where('urls.submit', route('portal.b2b.submit')));

        config()->set('portal.telegram.portal_url', 'https://mini.example.test');
        $menu = app(GetTelegramMenu::class)->handle('ru');
        $b2b = collect($menu)->firstWhere('key', 'b2b');

        self::assertSame('🚀 Хочешь себе такого бота? / Развить бизнес', $b2b['label']);
        self::assertSame(
            rtrim((string) config('portal.telegram.portal_url'), '/').'/portal/telegram/launch/b2b',
            $b2b['url'],
        );
    }

    public function test_portal_b2b_answer_keeps_the_client_in_the_b2b_journey_and_exposes_the_request_step(): void
    {
        $fixture = $this->fixture(false);
        $client = $fixture['client'];

        $this->withSession(['client_portal.client_id' => $client->getKey()])
            ->post(route('portal.profile.b2b-answer'), [
                'b2b_specialist_answer' => 'yes',
                'return_to' => 'b2b',
            ])
            ->assertRedirect(route('portal.b2b'));

        $this->withSession(['client_portal.client_id' => $client->getKey()])
            ->get(route('portal.b2b'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
                ->component('Portal/B2b')
                ->where('b2bSpecialistAnswer', 'yes')
                ->where('configurationReady', true)
                ->has('availability.slots'));

        $this->withSession(['client_portal.client_id' => $client->getKey()])
            ->get(route('portal.profile'))
            ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
                ->component('Portal/Profile')
                ->where('b2bSpecialistAnswer', 'yes'));
    }

    public function test_b2b_readiness_exposes_human_safe_configuration_statuses(): void
    {
        $fixture = $this->fixture();

        self::assertSame([
            'durationConfigured' => true,
            'calendarConfigured' => true,
            'automaticZoomConfigured' => false,
            'manualLinkFallbackAvailable' => true,
        ], app(GetB2bSalesCallReadiness::class)->handle());
    }

    public function test_b2b_readiness_flags_missing_duration(): void
    {
        $fixture = $this->fixture();

        app(ClearOrganizationSetting::class)->handle(
            $fixture['admin'],
            OrganizationSettingKey::B2bSalesCallDurationMinutes,
        );

        self::assertFalse(app(GetB2bSalesCallReadiness::class)->handle()['durationConfigured']);
    }

    public function test_b2b_readiness_flags_missing_calendar_without_eligible_specialists(): void
    {
        $organization = Organization::factory()->create(['timezone' => 'UTC']);
        $this->setOrganization($organization);

        self::assertSame([
            'durationConfigured' => false,
            'calendarConfigured' => false,
            'automaticZoomConfigured' => false,
            'manualLinkFallbackAvailable' => true,
        ], app(GetB2bSalesCallReadiness::class)->handle());
    }

    public function test_b2b_readiness_calendar_uses_only_the_current_organization_eligible_specialists(): void
    {
        $first = $this->fixture(false);
        $secondOrganization = Organization::factory()->create(['timezone' => 'UTC']);
        $secondAdmin = User::factory()->forOrganization($secondOrganization)->create();
        $secondSpecialist = Specialist::factory()->forOrganization($secondOrganization)->create(['timezone' => 'UTC']);
        $this->setOrganization($secondOrganization);

        self::assertFalse(app(GetB2bSalesCallReadiness::class)->handle()['calendarConfigured']);

        app(SetSpecialistWorkingHours::class)->handle($secondAdmin, $secondSpecialist, [[
            'weekday' => 1,
            'start_time' => '09:00',
            'end_time' => '19:00',
        ]]);

        self::assertTrue(app(GetB2bSalesCallReadiness::class)->handle()['calendarConfigured']);
        self::assertNotSame($first['organization']->getKey(), $secondOrganization->getKey());
    }

    public function test_b2b_readiness_reports_only_an_active_matching_zoom_credential(): void
    {
        $fixture = $this->fixture();
        $credentialName = (string) config('b2b.credential_name');
        $otherOrganization = Organization::factory()->create(['timezone' => 'UTC']);

        $this->credential($otherOrganization, 'zoom', $credentialName, CredentialStatus::Active);
        self::assertFalse(app(GetB2bSalesCallReadiness::class)->handle()['automaticZoomConfigured']);

        $this->credential($fixture['organization'], 'other-provider', $credentialName, CredentialStatus::Active);
        $this->credential($fixture['organization'], 'zoom', 'other-name', CredentialStatus::Active);
        $disabled = $this->credential($fixture['organization'], 'zoom', $credentialName, CredentialStatus::Disabled);

        $readiness = app(GetB2bSalesCallReadiness::class)->handle();
        self::assertTrue($readiness['durationConfigured']);
        self::assertTrue($readiness['calendarConfigured']);
        self::assertFalse($readiness['automaticZoomConfigured']);
        self::assertTrue($readiness['manualLinkFallbackAvailable']);

        $disabled->forceFill(['status' => CredentialStatus::Active])->save();

        self::assertTrue(app(GetB2bSalesCallReadiness::class)->handle()['automaticZoomConfigured']);
    }

    public function test_zoom_configuration_is_tenant_scoped_encrypted_write_only_and_preserves_secret_on_disable(): void
    {
        $fixture = $this->fixture();
        $credentialName = (string) config('b2b.credential_name');

        app(SaveB2bZoomConfiguration::class)->handle(
            actor: $fixture['admin'],
            accountId: 'account-'.$fixture['organization']->getKey(),
            clientId: 'client-'.$fixture['organization']->getKey(),
            clientSecret: 'secret-'.$fixture['organization']->getKey(),
            hostUserId: 'host-'.$fixture['organization']->getKey(),
            enabled: true,
        );

        $storedCredentials = DB::table('organization_credentials')
            ->where('organization_id', $fixture['organization']->getKey())
            ->where('credential_name', $credentialName)
            ->value('credentials');
        self::assertIsString($storedCredentials);
        self::assertStringNotContainsString('secret-'.$fixture['organization']->getKey(), $storedCredentials);

        $configuration = app(GetB2bZoomConfiguration::class)->handle();
        self::assertTrue($configuration['configured']);
        self::assertTrue($configuration['hasClientSecret']);
        self::assertArrayNotHasKey('clientSecret', $configuration);

        $this->actingAs($fixture['admin']);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $mounted = Livewire::actingAs($fixture['admin'])->test(SchedulingConfiguration::class);
        self::assertNull($mounted->instance()->data['zoom_client_secret']);
        $mounted->assertDontSee('secret-'.$fixture['organization']->getKey());

        app(SaveB2bZoomConfiguration::class)->handle(
            actor: $fixture['admin'],
            accountId: 'account-'.$fixture['organization']->getKey(),
            clientId: 'client-'.$fixture['organization']->getKey(),
            clientSecret: null,
            hostUserId: 'host-'.$fixture['organization']->getKey(),
            enabled: false,
        );

        $disabled = app(GetB2bZoomConfiguration::class)->handle();
        self::assertFalse($disabled['configured']);
        self::assertFalse($disabled['enabled']);
        self::assertTrue($disabled['hasClientSecret']);

        $other = $this->fixture(false);
        self::assertFalse(app(GetB2bZoomConfiguration::class)->handle()['exists']);
        $this->setOrganization($fixture['organization']);
        self::assertTrue(app(GetB2bZoomConfiguration::class)->handle()['exists']);
        self::assertNotSame($other['organization']->getKey(), $fixture['organization']->getKey());
    }

    public function test_scheduling_configuration_scrubs_zoom_secret_after_success_and_blank_edit_preserves_it(): void
    {
        $fixture = $this->fixture();
        $this->actingAs($fixture['admin']);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $secret = 'livewire-success-secret';
        $form = [
            'specialist_id' => $fixture['specialist']->getKey(),
            'lead_time_minutes' => 0,
            'cancellation_cutoff_minutes' => 0,
            'b2b_sales_call_duration_minutes' => 60,
            'b2b_zoom_host_licensed' => true,
            'office_location' => null,
            'zoom_enabled' => true,
            'zoom_account_id' => 'livewire-account',
            'zoom_client_id' => 'livewire-client',
            'zoom_client_secret' => $secret,
            'zoom_host_user_id' => 'livewire-host',
            'working_hours' => [[
                'weekday' => 1,
                'start_time' => '09:00',
                'end_time' => '19:00',
            ]],
            'acknowledge_impact' => false,
            'impact_digest' => null,
        ];

        Livewire::actingAs($fixture['admin'])
            ->test(SchedulingConfiguration::class)
            ->fillForm($form)
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('data.zoom_client_secret', null)
            ->assertDontSee($secret);

        $credential = OrganizationCredential::query()
            ->where('organization_id', $fixture['organization']->getKey())
            ->where('provider', 'zoom')
            ->sole();
        self::assertSame($secret, $credential->credentials['client_secret']);

        Livewire::actingAs($fixture['admin'])
            ->test(SchedulingConfiguration::class)
            ->fillForm([...$form, 'zoom_enabled' => false, 'zoom_client_secret' => null])
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('data.zoom_client_secret', null);

        self::assertSame($secret, $credential->refresh()->credentials['client_secret']);
    }

    public function test_scheduling_configuration_scrubs_zoom_secret_when_an_unrelated_field_fails_validation(): void
    {
        $fixture = $this->fixture();
        $this->actingAs($fixture['admin']);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $secret = 'livewire-unrelated-error-secret';
        app(CreateBooking::class)->handle(
            actor: $fixture['client'],
            client: $fixture['client'],
            specialist: $fixture['specialist'],
            service: $fixture['service'],
            startsAt: $this->slot(),
            format: VisitFormat::Office,
            idempotencyKey: 'livewire-secret-impact',
        );

        Livewire::actingAs($fixture['admin'])
            ->test(SchedulingConfiguration::class)
            ->fillForm([
                'specialist_id' => $fixture['specialist']->getKey(),
                'lead_time_minutes' => 0,
                'zoom_enabled' => true,
                'zoom_account_id' => 'error-account',
                'zoom_client_id' => 'error-client',
                'zoom_client_secret' => $secret,
                'zoom_host_user_id' => 'error-host',
                'working_hours' => [[
                    'weekday' => 1,
                    'start_time' => '09:00',
                    'end_time' => '14:00',
                ]],
                'acknowledge_impact' => false,
                'impact_digest' => null,
            ])
            ->call('save')
            ->assertHasErrors('schedule_impact')
            ->assertSet('data.zoom_client_secret', null)
            ->assertDontSee($secret);
    }

    public function test_scheduling_configuration_scrubs_zoom_secret_when_zoom_validation_fails(): void
    {
        $fixture = $this->fixture();
        $this->actingAs($fixture['admin']);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $secret = 'livewire-zoom-error-secret';

        Livewire::actingAs($fixture['admin'])
            ->test(SchedulingConfiguration::class)
            ->fillForm([
                'specialist_id' => $fixture['specialist']->getKey(),
                'zoom_enabled' => true,
                'zoom_account_id' => null,
                'zoom_client_id' => 'error-client',
                'zoom_client_secret' => $secret,
                'zoom_host_user_id' => 'error-host',
                'working_hours' => [[
                    'weekday' => 1,
                    'start_time' => '09:00',
                    'end_time' => '19:00',
                ]],
                'acknowledge_impact' => false,
                'impact_digest' => null,
            ])
            ->call('save')
            ->assertHasErrors('account_id')
            ->assertSet('data.zoom_client_secret', null)
            ->assertDontSee($secret);
    }

    public function test_portal_b2b_submission_redirects_to_a_durable_scheduled_state_projection(): void
    {
        $fixture = $this->fixture();
        $client = $fixture['client'];
        $startsAt = $this->slot()->toIso8601String();

        $this->withSession(['client_portal.client_id' => $client->getKey()])
            ->post(route('portal.b2b.submit'), [
                'specialist_id' => $fixture['specialist']->getKey(),
                'starts_at' => $startsAt,
                'submission_key' => 'portal-confirmation',
            ])
            ->assertRedirect(route('portal.b2b'));

        $this->withSession(['client_portal.client_id' => $client->getKey()])
            ->get(route('portal.b2b'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
                ->component('Portal/B2b')
                ->where('currentRequest.meetingMode', VideoMeetingMode::Automatic->value)
                ->where('currentRequest.meetingStatus', 'automatic_pending')
                ->where('currentRequest.startsAt', $startsAt)
                ->where('currentRequest.specialistName', $fixture['specialist']->display_name)
                ->where('currentRequest.meetingUrl', null));

        self::assertSame(1, B2bSalesCall::query()
            ->where('organization_id', $fixture['organization']->getKey())
            ->where('client_id', $client->getKey())
            ->where('status', B2bSalesCallStatus::Scheduled->value)
            ->count());
        self::assertSame($startsAt, app(GetPortalB2bRequest::class)->handle($client)['startsAt']);
    }

    public function test_manual_b2b_links_require_safe_https_and_become_the_client_join_projection(): void
    {
        $fixture = $this->fixture();

        foreach ([null, '', 'http://meet.example.test/call', 'https://user:pass@meet.example.test/call'] as $index => $url) {
            try {
                $this->submit(
                    fixture: $fixture,
                    key: 'manual-invalid-'.$index,
                    meetingMode: VideoMeetingMode::Manual,
                    manualMeetingUrl: $url,
                );
                self::fail('An unsafe or blank manual URL was accepted.');
            } catch (ValidationException $exception) {
                self::assertArrayHasKey('manual_meeting_url', $exception->errors());
            }
        }

        $lead = $this->submit(
            fixture: $fixture,
            key: 'manual-projection',
            meetingMode: VideoMeetingMode::Manual,
            manualMeetingUrl: 'https://meet.example.test/client-call',
        );
        $client = $fixture['client'];

        $this->withSession(['client_portal.client_id' => $client->getKey()])
            ->get(route('portal.b2b'))
            ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
                ->where('currentRequest.meetingMode', VideoMeetingMode::Manual->value)
                ->where('currentRequest.meetingStatus', 'ready')
                ->where('currentRequest.meetingUrl', 'https://meet.example.test/client-call'));
        self::assertSame('https://meet.example.test/client-call', $lead->salesCall()->firstOrFail()->manual_meeting_url);
    }

    public function test_licensed_zoom_host_allows_switching_manual_mode_to_automatic_for_a_sixty_minute_call(): void
    {
        $fixture = $this->fixture();
        $lead = $this->submit(
            fixture: $fixture,
            key: 'manual-to-automatic',
            meetingMode: VideoMeetingMode::Manual,
            manualMeetingUrl: 'https://meet.example.test/client-call',
        );
        $call = $lead->salesCall()->firstOrFail();

        $automatic = app(SetB2bSalesCallMeetingMode::class)->handle(
            actor: $fixture['admin'],
            salesCall: $call,
            mode: VideoMeetingMode::Automatic,
            manualMeetingUrl: null,
            expectedEventVersion: $call->event_version,
        );

        self::assertNull($automatic->manual_meeting_url);
        self::assertSame(VideoMeetingSyncStatus::Pending, $automatic->provider_sync_status);
        self::assertSame(VideoMeetingOperation::Create, $automatic->provider_operation);
        $event = IntegrationEvent::query()->sole();

        Queue::assertPushedOn(
            (string) config('b2b.queue'),
            ProcessB2bProviderSyncEvent::class,
            static fn (ProcessB2bProviderSyncEvent $job): bool => $job->integrationEventId === $event->getKey(),
        );
    }

    public function test_basic_zoom_host_rejects_manual_to_automatic_without_mutating_the_sales_call(): void
    {
        $fixture = $this->fixture();
        app(ClearOrganizationSetting::class)->handle(
            $fixture['admin'],
            OrganizationSettingKey::B2bZoomHostLicensed,
        );
        $lead = $this->submit(
            fixture: $fixture,
            key: 'basic-manual-to-automatic-rejected',
            meetingMode: VideoMeetingMode::Manual,
            manualMeetingUrl: 'https://meet.example.test/basic-manual-call',
        );
        $call = $lead->salesCall()->firstOrFail();
        $before = $this->salesCallModeState($call->fresh());

        try {
            app(SetB2bSalesCallMeetingMode::class)->handle(
                actor: $fixture['admin'],
                salesCall: $call,
                mode: VideoMeetingMode::Automatic,
                expectedEventVersion: $call->event_version,
            );
            self::fail('A Basic Zoom host accepted an unsupported Manual to Automatic transition.');
        } catch (ValidationException $exception) {
            self::assertSame(
                'Automatic Zoom sales-call duration exceeds the current host capability of 40 minutes. Enable a licensed Zoom host or use a shorter business duration.',
                $exception->errors()['configuration'][0],
            );
        }

        self::assertSame($before, $this->salesCallModeState($call->fresh()));
        self::assertSame(0, IntegrationEvent::query()->count());
        Queue::assertNothingPushed();
    }

    public function test_basic_zoom_host_allows_manual_to_automatic_at_its_duration_limit(): void
    {
        $fixture = $this->fixture();
        app(ClearOrganizationSetting::class)->handle(
            $fixture['admin'],
            OrganizationSettingKey::B2bZoomHostLicensed,
        );
        app(SetOrganizationSetting::class)->handle(
            $fixture['admin'],
            OrganizationSettingKey::B2bSalesCallDurationMinutes,
            40,
        );
        $lead = $this->submit(
            fixture: $fixture,
            key: 'basic-manual-to-automatic-allowed',
            meetingMode: VideoMeetingMode::Manual,
            manualMeetingUrl: 'https://meet.example.test/basic-manual-call',
        );
        $call = $lead->salesCall()->firstOrFail();

        $automatic = app(SetB2bSalesCallMeetingMode::class)->handle(
            actor: $fixture['admin'],
            salesCall: $call,
            mode: VideoMeetingMode::Automatic,
            expectedEventVersion: $call->event_version,
        );

        self::assertSame(VideoMeetingMode::Automatic, $automatic->meeting_mode);
        self::assertSame(VideoMeetingSyncStatus::Pending, $automatic->provider_sync_status);
        self::assertSame(VideoMeetingOperation::Create, $automatic->provider_operation);
        self::assertSame(1, IntegrationEvent::query()->count());
        Queue::assertPushedOn((string) config('b2b.queue'), ProcessB2bProviderSyncEvent::class);
    }

    public function test_manual_to_automatic_uses_the_locked_call_duration_after_the_organization_setting_changes(): void
    {
        $fixture = $this->fixture();
        app(ClearOrganizationSetting::class)->handle(
            $fixture['admin'],
            OrganizationSettingKey::B2bZoomHostLicensed,
        );
        $lead = $this->submit(
            fixture: $fixture,
            key: 'manual-to-automatic-historical-duration',
            meetingMode: VideoMeetingMode::Manual,
            manualMeetingUrl: 'https://meet.example.test/historical-manual-call',
        );
        $call = $lead->salesCall()->firstOrFail();
        $before = $this->salesCallModeState($call->fresh());
        app(SetOrganizationSetting::class)->handle(
            $fixture['admin'],
            OrganizationSettingKey::B2bSalesCallDurationMinutes,
            30,
        );

        try {
            app(SetB2bSalesCallMeetingMode::class)->handle(
                actor: $fixture['admin'],
                salesCall: $call,
                mode: VideoMeetingMode::Automatic,
                expectedEventVersion: $call->event_version,
            );
            self::fail('The transition trusted the changed organization duration instead of the scheduled call.');
        } catch (ValidationException $exception) {
            self::assertSame(
                'Automatic Zoom sales-call duration exceeds the current host capability of 40 minutes. Enable a licensed Zoom host or use a shorter business duration.',
                $exception->errors()['configuration'][0],
            );
        }

        self::assertSame($before, $this->salesCallModeState($call->fresh()));
        self::assertSame(0, IntegrationEvent::query()->count());
        Queue::assertNothingPushed();
    }

    public function test_b2b_answer_return_to_rejects_external_and_arbitrary_values_without_mutation(): void
    {
        $fixture = $this->fixture(false);
        $client = $fixture['client'];

        foreach ([
            'https://evil.example',
            '//evil.example',
            'admin',
            '../profile',
            'b2b/anything',
        ] as $returnTo) {
            $response = $this->withSession(['client_portal.client_id' => $client->getKey()])
                ->post(route('portal.profile.b2b-answer'), [
                    'b2b_specialist_answer' => 'yes',
                    'return_to' => $returnTo,
                ]);

            $response->assertSessionHasErrors('return_to');
            self::assertStringNotContainsString('evil.example', (string) $response->headers->get('Location'));
            self::assertStringNotContainsString('/admin', (string) $response->headers->get('Location'));
        }

        self::assertFalse(BroadcastClientProfile::query()->where('client_id', $client->getKey())->exists());
    }

    public function test_b2b_answer_return_to_profile_is_an_allowed_continuation(): void
    {
        $fixture = $this->fixture(false);

        $this->withSession(['client_portal.client_id' => $fixture['client']->getKey()])
            ->post(route('portal.profile.b2b-answer'), [
                'b2b_specialist_answer' => 'yes',
                'return_to' => 'profile',
            ])
            ->assertRedirect(route('portal.profile'));

        self::assertSame(
            'yes',
            BroadcastClientProfile::query()
                ->where('client_id', $fixture['client']->getKey())
                ->firstOrFail()
                ->getRawOriginal('b2b_specialist_answer'),
        );
    }

    public function test_lead_submission_creates_one_nonclinical_lead_and_scheduled_occupancy(): void
    {
        $fixture = $this->fixture();
        $lead = $this->submit($fixture, 'portal-lead');
        $call = $lead->salesCall()->firstOrFail();

        self::assertSame(B2bLeadStatus::ZoomScheduled, $lead->status);
        self::assertSame(B2bSpecialistAnswer::Yes, $lead->b2b_specialist_answer);
        self::assertSame(B2bLeadSource::Portal, $lead->source_channel);
        self::assertSame(VideoMeetingSyncStatus::Pending, $call->provider_sync_status);
        self::assertSame($fixture['organization']->getKey(), $call->organization_id);
        self::assertSame($fixture['client']->getKey(), $call->client_id);
        self::assertSame($fixture['specialist']->getKey(), $call->specialist_id);
        self::assertSame(1, UnavailablePeriod::query()->where('b2b_sales_call_id', $call->getKey())->count());
        self::assertSame(0, Booking::query()->count());
        self::assertSame(0, DB::table('financial_obligations')->count());
        self::assertSame(0, DB::table('financial_ledger_entries')->count());
        self::assertSame(1, B2bLead::query()->where('organization_id', $fixture['organization']->getKey())->count());

        $scheduling = app(SchedulingAnalytics::class)->handle(
            $fixture['admin'],
            DashboardPeriod::fromFilters(
                ['period' => DashboardPeriod::Today],
                $fixture['organization']->defaultTimezone(),
                CarbonImmutable::now('UTC'),
            ),
        );
        self::assertSame(0, $scheduling->bookings);
        self::assertSame(0, $scheduling->visits);
        self::assertSame(0, $scheduling->cancellations);
        self::assertSame(0, $scheduling->reschedules);

        $providerEvent = IntegrationEvent::query()
            ->where('event_type', IntegrationEventType::B2bSalesCallProviderSync->value)
            ->sole();
        $payload = json_encode($providerEvent->payload, JSON_THROW_ON_ERROR);
        self::assertArrayNotHasKey('medical', $providerEvent->payload);
        self::assertArrayNotHasKey('health', $providerEvent->payload);
        self::assertStringNotContainsString('medical', strtolower($payload));
    }

    public function test_provider_principal_is_persisted_with_the_known_meeting_and_sync_event(): void
    {
        $provider = new FakeVideoMeetingProvider;
        $this->app->instance(VideoMeetingProvider::class, $provider);
        $fixture = $this->fixture();
        $lead = $this->submit($fixture, 'provider-principal-persistence');
        $call = $lead->salesCall()->firstOrFail();

        app(SyncB2bSalesCallProvider::class)->handle(IntegrationEvent::query()->sole()->getKey());

        $ready = $call->fresh();
        self::assertSame('test-account', $ready->provider_account_id);
        self::assertSame('test-host', $ready->provider_host_user_id);
        self::assertSame('test-account', $ready->providerIdentity()?->providerAccountAffinity?->accountId);
        self::assertSame('test-host', $ready->providerIdentity()?->providerAccountAffinity?->hostUserId);

        app(RescheduleB2bSalesCall::class)->handle(
            actor: $fixture['admin'],
            salesCall: $ready,
            newStartsAt: $this->slot(16),
            requestedTimezone: 'UTC',
            expectedEventVersion: $ready->event_version,
        );

        $event = IntegrationEvent::query()->latest('id')->firstOrFail();
        self::assertSame('test-account', $event->payload['provider_account_id']);
        self::assertSame('test-host', $event->payload['provider_host_user_id']);
        self::assertArrayNotHasKey('client_secret', $event->payload);
        self::assertArrayNotHasKey('access_token', $event->payload);
    }

    public function test_retry_is_idempotent_but_a_later_submission_after_cancellation_is_allowed(): void
    {
        $fixture = $this->fixture();
        $first = $this->submit($fixture, 'same-logical-submit');
        $retry = $this->submit($fixture, 'same-logical-submit');

        self::assertSame($first->getKey(), $retry->getKey());
        self::assertSame(1, B2bLead::query()->count());
        self::assertSame(1, B2bSalesCall::query()->count());

        try {
            $this->submit($fixture, 'same-logical-submit', 16);
            self::fail('The same idempotency key was accepted for a different request.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('submission_key', $exception->errors());
        }

        $call = $first->salesCall()->firstOrFail();
        app(CancelB2bSalesCall::class)->handle($fixture['admin'], $call, $call->event_version);
        $later = $this->submit($fixture, 'later-genuine-submit');

        self::assertNotSame($first->getKey(), $later->getKey());
        self::assertSame(2, B2bLead::query()->count());
        self::assertSame(2, B2bSalesCall::query()->count());
    }

    public function test_cross_organization_records_are_rejected_and_crm_queries_are_scoped_and_paginated(): void
    {
        $first = $this->fixture();
        $firstLead = $this->submit($first, 'first-org');
        $second = $this->fixture();
        $secondLead = $this->submit($second, 'second-org');

        $this->setOrganization($first['organization']);

        try {
            $this->submit([
                ...$first,
                'client' => $second['client'],
            ], 'forged-client');
            self::fail('A forged cross-organization client was accepted.');
        } catch (AuthorizationException) {
            self::assertTrue(true);
        }

        $unauthorized = User::factory()->create();
        try {
            app(ListB2bLeadsForCrm::class)->query($unauthorized)->get();
            self::fail('An organizationless user was allowed to read B2B leads.');
        } catch (AuthorizationException) {
            self::assertTrue(true);
        }

        $page = app(ListB2bLeadsForCrm::class)->query($first['admin'])->paginate(1);

        self::assertSame(1, $page->total());
        self::assertCount(1, $page->items());
        self::assertSame($firstLead->getKey(), $page->items()[0]->getKey());
        self::assertNotSame($secondLead->getKey(), $page->items()[0]->getKey());
    }

    public function test_timezone_is_saved_as_utc_while_specialist_calendar_timezone_is_preserved(): void
    {
        $fixture = $this->fixture(specialistTimezone: 'Asia/Almaty', clientTimezone: 'Asia/Almaty');
        $lead = $this->submit(
            fixture: $fixture,
            key: 'almaty-time',
            startsAt: CarbonImmutable::create(2026, 8, 31, 15, 0, 0, 'Asia/Almaty'),
            requestedTimezone: 'Asia/Almaty',
        );
        $call = $lead->salesCall()->firstOrFail();

        self::assertSame('2026-08-31T10:00:00+00:00', $call->startsAtUtc()->toIso8601String());
        self::assertSame('Asia/Almaty', $call->schedule_timezone);
        self::assertSame('Asia/Almaty', $call->requested_timezone);
    }

    public function test_ordinary_booking_blocks_a_b2b_sales_call(): void
    {
        $fixture = $this->fixture();
        $booking = app(CreateBooking::class)->handle(
            actor: $fixture['client'],
            client: $fixture['client'],
            specialist: $fixture['specialist'],
            service: $fixture['service'],
            startsAt: $this->slot(),
            format: VisitFormat::Office,
            idempotencyKey: 'blocking-booking',
        );
        $otherClient = $this->client($fixture);

        try {
            $this->submit([
                ...$fixture,
                'client' => $otherClient,
            ], 'blocked-by-booking');
            self::fail('A B2B call was allowed to overlap an ordinary booking.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('starts_at', $exception->errors());
        }

        self::assertSame(1, Booking::query()->whereKey($booking->getKey())->count());
        self::assertSame(0, B2bSalesCall::query()->count());
        self::assertSame(0, B2bLead::query()->count());
    }

    public function test_b2b_sales_call_blocks_booking_and_another_call_but_not_another_specialist(): void
    {
        $fixture = $this->fixture();
        $this->submit($fixture, 'first-sales-call');
        $otherClient = $this->client($fixture);

        try {
            app(CreateBooking::class)->handle(
                actor: $otherClient,
                client: $otherClient,
                specialist: $fixture['specialist'],
                service: $fixture['service'],
                startsAt: $this->slot(),
                format: VisitFormat::Office,
                idempotencyKey: 'blocked-booking',
            );
            self::fail('An ordinary booking was allowed to overlap a B2B call.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('startsAt', $exception->errors());
        }

        try {
            $this->submit([
                ...$fixture,
                'client' => $otherClient,
            ], 'second-sales-call');
            self::fail('Two B2B calls were allowed to overlap one specialist.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('starts_at', $exception->errors());
        }

        $otherSpecialist = Specialist::factory()->forOrganization($fixture['organization'])->create([
            'timezone' => 'UTC',
        ]);
        app(AssignSpecialistToService::class)->handle($fixture['admin'], $otherSpecialist, $fixture['service']);
        app(SetSpecialistWorkingHours::class)->handle($fixture['admin'], $otherSpecialist, [[
            'weekday' => 1,
            'start_time' => '09:00',
            'end_time' => '19:00',
        ]]);
        $otherLead = $this->submit([
            ...$fixture,
            'specialist' => $otherSpecialist,
            'client' => $this->client($fixture),
        ], 'other-specialist');

        self::assertSame($otherSpecialist->getKey(), $otherLead->salesCall()->value('specialist_id'));
        self::assertSame(0, Booking::query()->count());
    }

    public function test_unavailable_period_blocks_b2b_sales_call(): void
    {
        $fixture = $this->fixture();
        UnavailablePeriod::factory()->forSpecialist($fixture['specialist'])->create([
            'starts_at' => $this->slot(),
            'ends_at' => $this->slot()->addHour(),
            'created_by_user_id' => $fixture['admin']->getKey(),
        ]);

        try {
            $this->submit($fixture, 'blocked-by-unavailable');
            self::fail('A B2B call was allowed inside an unavailable period.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('starts_at', $exception->errors());
        }

        self::assertSame(0, B2bLead::query()->count());
    }

    public function test_missing_b2b_duration_keeps_interest_visible_but_prevents_scheduling(): void
    {
        $fixture = $this->fixture();
        app(ClearOrganizationSetting::class)->handle(
            $fixture['admin'],
            OrganizationSettingKey::B2bSalesCallDurationMinutes,
        );

        $projection = app(ListB2bSalesCallAvailability::class)->handle(
            client: $fixture['client'],
            dateFrom: '2026-08-31',
            dateTo: '2026-08-31',
            specialistId: $fixture['specialist']->getKey(),
            displayTimezone: 'UTC',
        );

        self::assertFalse($projection['configurationReady']);
        self::assertNull($projection['availability']);

        try {
            $this->submit($fixture, 'duration-not-configured');
            self::fail('A B2B sales call was scheduled without an organization duration.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('configuration', $exception->errors());
        }

        self::assertSame(0, B2bLead::query()->count());
        self::assertSame(0, B2bSalesCall::query()->count());
        self::assertSame(0, IntegrationEvent::query()->count());
    }

    public function test_scheduling_configuration_clears_duration_through_the_organizations_application_boundary(): void
    {
        $fixture = $this->fixture();
        $this->actingAs($fixture['admin']);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::actingAs($fixture['admin'])
            ->test(SchedulingConfiguration::class)
            ->fillForm([
                'specialist_id' => $fixture['specialist']->getKey(),
                'lead_time_minutes' => 0,
                'cancellation_cutoff_minutes' => 0,
                'b2b_sales_call_duration_minutes' => null,
                'b2b_zoom_host_licensed' => true,
                'office_location' => null,
                'working_hours' => [[
                    'weekday' => 1,
                    'start_time' => '09:00',
                    'end_time' => '19:00',
                ]],
                'acknowledge_impact' => false,
                'impact_digest' => null,
            ])
            ->call('save')
            ->assertHasNoErrors();

        self::assertSame(0, DB::table('organization_settings')
            ->where('organization_id', $fixture['organization']->getKey())
            ->where('setting_key', OrganizationSettingKey::B2bSalesCallDurationMinutes->value)
            ->count());
        self::assertSame(1, DB::table('organization_settings')
            ->where('organization_id', $fixture['organization']->getKey())
            ->where('setting_key', OrganizationSettingKey::B2bZoomHostLicensed->value)
            ->count());
    }

    public function test_clearing_b2b_duration_is_audited_tenant_scoped_and_keeps_history_unchanged(): void
    {
        $first = $this->fixture();
        $call = $this->submit($first, 'clear-duration-history')->salesCall()->firstOrFail();
        $historicalStartsAt = $call->startsAtUtc()->toIso8601String();
        $historicalEndsAt = $call->endsAtUtc()->toIso8601String();
        $staff = User::factory()->forOrganization($first['organization'], OrganizationRole::Staff)->create();

        try {
            app(ClearOrganizationSetting::class)->handle(
                $staff,
                OrganizationSettingKey::B2bSalesCallDurationMinutes,
            );
            self::fail('A staff member cleared the B2B scheduling duration.');
        } catch (AuthorizationException) {
            self::assertTrue(true);
        }

        $second = $this->fixture();
        $this->setOrganization($first['organization']);
        app(ClearOrganizationSetting::class)->handle(
            $first['admin'],
            OrganizationSettingKey::B2bSalesCallDurationMinutes,
        );

        self::assertNull(app(GetB2bSalesCallDuration::class)->handle());
        self::assertSame($historicalStartsAt, $call->fresh()->startsAtUtc()->toIso8601String());
        self::assertSame($historicalEndsAt, $call->fresh()->endsAtUtc()->toIso8601String());
        self::assertSame(0, DB::table('organization_settings')
            ->where('organization_id', $first['organization']->getKey())
            ->where('setting_key', OrganizationSettingKey::B2bSalesCallDurationMinutes->value)
            ->count());
        self::assertSame(1, DB::table('organization_settings')
            ->where('organization_id', $second['organization']->getKey())
            ->where('setting_key', OrganizationSettingKey::B2bSalesCallDurationMinutes->value)
            ->count());
        self::assertSame(1, AuditEvent::query()
            ->where('organization_id', $first['organization']->getKey())
            ->where('action', 'organization.setting.removed')
            ->count());

        $projection = app(ListB2bSalesCallAvailability::class)->handle(
            client: $first['client'],
            dateFrom: '2026-08-31',
            dateTo: '2026-08-31',
            specialistId: $first['specialist']->getKey(),
            displayTimezone: 'UTC',
        );
        self::assertFalse($projection['configurationReady']);
        self::assertNull($projection['availability']);
    }

    public function test_configured_b2b_duration_is_used_by_availability_interval_and_zoom_request(): void
    {
        $provider = new FakeVideoMeetingProvider;
        $this->app->instance(VideoMeetingProvider::class, $provider);
        $fixture = $this->fixture();
        app(SetOrganizationSetting::class)->handle(
            $fixture['admin'],
            OrganizationSettingKey::B2bSalesCallDurationMinutes,
            45,
        );

        $projection = app(ListB2bSalesCallAvailability::class)->handle(
            client: $fixture['client'],
            dateFrom: '2026-08-31',
            dateTo: '2026-08-31',
            specialistId: $fixture['specialist']->getKey(),
            displayTimezone: 'UTC',
        );
        $slot = collect($projection['availability']['slots'])->firstWhere('startsAt', '2026-08-31T15:00:00+00:00');

        self::assertIsArray($slot);
        self::assertSame(45, (int) DB::table('organization_settings')
            ->where('organization_id', $fixture['organization']->getKey())
            ->where('setting_key', OrganizationSettingKey::B2bSalesCallDurationMinutes->value)
            ->value('integer_value'));
        self::assertSame('2026-08-31T15:45:00+00:00', $slot['endsAt']);

        $lead = $this->submit($fixture, 'duration-45');
        $call = $lead->salesCall()->firstOrFail();
        self::assertSame(45, $call->exactDuration()->minutes);
        self::assertSame('2026-08-31T15:45:00+00:00', $call->endsAtUtc()->toIso8601String());

        app(SyncB2bSalesCallProvider::class)->handle(IntegrationEvent::query()->sole()->getKey());

        self::assertSame(45, $provider->lastRequest?->durationMinutes);
    }

    public function test_malformed_historical_call_cannot_be_rescheduled(): void
    {
        $fixture = $this->fixture();
        $lead = $this->submit(
            fixture: $fixture,
            key: 'malformed-reschedule',
            meetingMode: VideoMeetingMode::Manual,
            manualMeetingUrl: 'https://meet.example.test/malformed-reschedule',
        );
        $call = $lead->salesCall()->firstOrFail();
        $this->withMalformedPersistedInterval($call, '2026-08-31 15:40:01', function () use ($fixture, $call): void {
            $before = $call->fresh()->getRawOriginal();
            $occupancyBefore = $call->occupancyPeriod()->firstOrFail()->getRawOriginal();

            try {
                app(RescheduleB2bSalesCall::class)->handle(
                    actor: $fixture['admin'],
                    salesCall: $call,
                    newStartsAt: $this->slot(17),
                    requestedTimezone: 'UTC',
                    expectedEventVersion: $call->event_version,
                );
                self::fail('A malformed historical sales-call interval was rescheduled.');
            } catch (ValidationException $exception) {
                self::assertSame(
                    'The stored sales-call interval is invalid and cannot be rescheduled.',
                    $exception->errors()['sales_call'][0],
                );
            }

            self::assertSame($before, $call->fresh()->getRawOriginal());
            self::assertSame($occupancyBefore, $call->occupancyPeriod()->firstOrFail()->getRawOriginal());
            self::assertSame(0, IntegrationEvent::query()->count());
            Queue::assertNothingPushed();
        });
    }

    public function test_malformed_historical_call_cannot_switch_manual_to_automatic(): void
    {
        $fixture = $this->fixture();
        $lead = $this->submit(
            fixture: $fixture,
            key: 'malformed-mode-switch',
            meetingMode: VideoMeetingMode::Manual,
            manualMeetingUrl: 'https://meet.example.test/malformed-mode-switch',
        );
        $call = $lead->salesCall()->firstOrFail();
        $this->withMalformedPersistedInterval($call, '2026-08-31 15:39:59', function () use ($fixture, $call): void {
            $before = $call->fresh()->getRawOriginal();

            try {
                app(SetB2bSalesCallMeetingMode::class)->handle(
                    actor: $fixture['admin'],
                    salesCall: $call,
                    mode: VideoMeetingMode::Automatic,
                    expectedEventVersion: $call->event_version,
                );
                self::fail('A malformed historical sales-call interval switched to automatic mode.');
            } catch (ValidationException $exception) {
                self::assertSame(
                    'The stored sales-call interval is invalid and cannot be changed to automatic mode.',
                    $exception->errors()['sales_call'][0],
                );
            }

            self::assertSame($before, $call->fresh()->getRawOriginal());
            self::assertSame(0, IntegrationEvent::query()->count());
            Queue::assertNothingPushed();
        });
    }

    public function test_malformed_historical_call_cannot_start_provider_reconciliation(): void
    {
        $provider = Mockery::mock(VideoMeetingProvider::class);
        $provider->shouldReceive('name')->andReturn('zoom');
        $provider->shouldNotReceive('createMeeting');
        $provider->shouldNotReceive('findMeeting');
        $provider->shouldNotReceive('getMeeting');
        $provider->shouldNotReceive('updateMeeting');
        $provider->shouldNotReceive('cancelMeeting');
        $provider->shouldNotReceive('obtainHostLaunchUrl');
        $this->app->instance(VideoMeetingProvider::class, $provider);

        $fixture = $this->fixture();
        $lead = $this->submit($fixture, 'malformed-provider-request');
        $call = $lead->salesCall()->firstOrFail();
        $this->withMalformedPersistedInterval($call, '2026-08-31 15:00:00.000001', function () use ($call): void {
            $event = IntegrationEvent::query()->sole();

            app(SyncB2bSalesCallProvider::class)->handle($event->getKey());

            $final = $call->fresh();
            self::assertSame(VideoMeetingSyncStatus::ReconciliationRequired, $final->provider_sync_status);
            self::assertSame('sales_call_interval_invalid', $final->provider_error_code);
            self::assertSame('failed', $event->fresh()->status->value);
        });
    }

    public function test_malformed_historical_call_cannot_start_host_launch_provider_io(): void
    {
        $provider = new FakeVideoMeetingProvider;
        $this->app->instance(VideoMeetingProvider::class, $provider);
        $fixture = $this->fixture();
        $lead = $this->submit($fixture, 'malformed-host-launch');
        $call = $lead->salesCall()->firstOrFail();
        app(SyncB2bSalesCallProvider::class)->handle(IntegrationEvent::query()->sole()->getKey());
        self::assertSame(VideoMeetingSyncStatus::Ready, $call->fresh()->provider_sync_status);

        $this->withMalformedPersistedInterval($call, '2026-08-31 16:00:00.000001', function () use ($fixture, $call, $provider): void {
            try {
                app(GetB2bSalesCallHostLaunchUrl::class)->handle($fixture['admin'], $call->fresh());
                self::fail('A host launch request was built from a malformed sales-call interval.');
            } catch (ValidationException $exception) {
                self::assertArrayHasKey('provider', $exception->errors());
            }

            self::assertSame(0, $provider->hostLaunchCount);
        });
    }

    public function test_zoom_basic_capability_limits_automatic_duration_without_truncating_business_duration(): void
    {
        $provider = new FakeVideoMeetingProvider;
        $this->app->instance(VideoMeetingProvider::class, $provider);
        $fixture = $this->fixture();
        app(ClearOrganizationSetting::class)->handle(
            $fixture['admin'],
            OrganizationSettingKey::B2bZoomHostLicensed,
        );
        app(SetOrganizationSetting::class)->handle(
            $fixture['admin'],
            OrganizationSettingKey::B2bSalesCallDurationMinutes,
            40,
        );

        $basicProjection = app(ListB2bSalesCallAvailability::class)->handle(
            client: $fixture['client'],
            dateFrom: '2026-08-31',
            dateTo: '2026-08-31',
            specialistId: $fixture['specialist']->getKey(),
            displayTimezone: 'UTC',
        );
        self::assertTrue($basicProjection['configurationReady']);
        $basicCall = $this->submit($fixture, 'zoom-basic-40')->salesCall()->firstOrFail();
        self::assertSame('2026-08-31T15:40:00+00:00', $basicCall->endsAtUtc()->toIso8601String());

        app(SetOrganizationSetting::class)->handle(
            $fixture['admin'],
            OrganizationSettingKey::B2bSalesCallDurationMinutes,
            41,
        );
        $unsupportedProjection = app(ListB2bSalesCallAvailability::class)->handle(
            client: $fixture['client'],
            dateFrom: '2026-08-31',
            dateTo: '2026-08-31',
            specialistId: $fixture['specialist']->getKey(),
            displayTimezone: 'UTC',
        );
        self::assertFalse($unsupportedProjection['configurationReady']);
        self::assertSame('zoom_duration_exceeds_capability', $unsupportedProjection['configurationIssue']);

        try {
            $this->submit($fixture, 'zoom-basic-41-rejected', 17);
            self::fail('A Basic Zoom host accepted an automatic sales call longer than 40 minutes.');
        } catch (ValidationException $exception) {
            self::assertSame('Automatic Zoom sales-call duration exceeds the current host capability of 40 minutes. Enable a licensed Zoom host or use a shorter business duration.', $exception->errors()['configuration'][0]);
        }
        self::assertSame(1, B2bSalesCall::query()->count());

        app(SetOrganizationSetting::class)->handle(
            $fixture['admin'],
            OrganizationSettingKey::B2bZoomHostLicensed,
            true,
        );
        $licensedProjection = app(ListB2bSalesCallAvailability::class)->handle(
            client: $fixture['client'],
            dateFrom: '2026-08-31',
            dateTo: '2026-08-31',
            specialistId: $fixture['specialist']->getKey(),
            displayTimezone: 'UTC',
        );
        self::assertTrue($licensedProjection['configurationReady']);
        $licensedCall = $this->submit($fixture, 'zoom-licensed-41', 17)->salesCall()->firstOrFail();
        self::assertSame('2026-08-31T17:41:00+00:00', $licensedCall->endsAtUtc()->toIso8601String());
    }

    public function test_zoom_host_capability_is_organization_scoped(): void
    {
        $basic = $this->fixture();
        app(ClearOrganizationSetting::class)->handle(
            $basic['admin'],
            OrganizationSettingKey::B2bZoomHostLicensed,
        );
        app(SetOrganizationSetting::class)->handle(
            $basic['admin'],
            OrganizationSettingKey::B2bSalesCallDurationMinutes,
            41,
        );
        $licensed = $this->fixture();

        $this->setOrganization($basic['organization']);
        $basicProjection = app(ListB2bSalesCallAvailability::class)->handle(
            client: $basic['client'],
            dateFrom: '2026-08-31',
            dateTo: '2026-08-31',
            specialistId: $basic['specialist']->getKey(),
            displayTimezone: 'UTC',
        );
        $this->setOrganization($licensed['organization']);
        $licensedProjection = app(ListB2bSalesCallAvailability::class)->handle(
            client: $licensed['client'],
            dateFrom: '2026-08-31',
            dateTo: '2026-08-31',
            specialistId: $licensed['specialist']->getKey(),
            displayTimezone: 'UTC',
        );

        self::assertFalse($basicProjection['configurationReady']);
        self::assertSame('zoom_duration_exceeds_capability', $basicProjection['configurationIssue']);
        self::assertTrue($licensedProjection['configurationReady']);
        self::assertNull($licensedProjection['configurationIssue']);
    }

    public function test_changing_b2b_duration_does_not_rewrite_historical_calls(): void
    {
        $fixture = $this->fixture();
        $first = $this->submit($fixture, 'historical-duration-first');
        $firstCall = $first->salesCall()->firstOrFail();
        app(SetOrganizationSetting::class)->handle(
            $fixture['admin'],
            OrganizationSettingKey::B2bSalesCallDurationMinutes,
            45,
        );

        self::assertSame('2026-08-31T16:00:00+00:00', $firstCall->fresh()->endsAtUtc()->toIso8601String());

        $second = $this->submit($fixture, 'historical-duration-second', 16);

        self::assertSame('2026-08-31T16:45:00+00:00', $second->salesCall()->firstOrFail()->endsAtUtc()->toIso8601String());
    }

    public function test_b2b_duration_is_organization_scoped_and_scheduling_permission_is_required(): void
    {
        $first = $this->fixture();
        app(SetOrganizationSetting::class)->handle(
            $first['admin'],
            OrganizationSettingKey::B2bSalesCallDurationMinutes,
            45,
        );
        $staff = User::factory()->forOrganization($first['organization'], OrganizationRole::Staff)->create();

        try {
            app(SetOrganizationSetting::class)->handle(
                $staff,
                OrganizationSettingKey::B2bSalesCallDurationMinutes,
                30,
            );
            self::fail('A staff member changed the B2B scheduling duration.');
        } catch (AuthorizationException) {
            self::assertTrue(true);
        }

        $second = $this->fixture();
        $this->setOrganization($first['organization']);
        $firstProjection = app(ListB2bSalesCallAvailability::class)->handle(
            client: $first['client'],
            dateFrom: '2026-08-31',
            dateTo: '2026-08-31',
            specialistId: $first['specialist']->getKey(),
            displayTimezone: 'UTC',
        );
        $this->setOrganization($second['organization']);
        $secondProjection = app(ListB2bSalesCallAvailability::class)->handle(
            client: $second['client'],
            dateFrom: '2026-08-31',
            dateTo: '2026-08-31',
            specialistId: $second['specialist']->getKey(),
            displayTimezone: 'UTC',
        );

        self::assertSame('2026-08-31T09:45:00+00:00', $firstProjection['availability']['slots'][0]['endsAt']);
        self::assertSame('2026-08-31T10:00:00+00:00', $secondProjection['availability']['slots'][0]['endsAt']);
    }

    public function test_b2b_availability_reuses_shared_booking_and_occupancy_conflicts_and_restores_cancelled_slot(): void
    {
        $fixture = $this->fixture();
        $bookingClient = $this->client($fixture);
        app(CreateBooking::class)->handle(
            actor: $bookingClient,
            client: $bookingClient,
            specialist: $fixture['specialist'],
            service: $fixture['service'],
            startsAt: $this->slot(15),
            format: VisitFormat::Office,
            idempotencyKey: 'availability-booking-conflict',
        );
        UnavailablePeriod::factory()->forSpecialist($fixture['specialist'])->create([
            'starts_at' => $this->slot(16),
            'ends_at' => $this->slot(17),
            'created_by_user_id' => $fixture['admin']->getKey(),
        ]);
        $secondLead = $this->submit([
            ...$fixture,
            'client' => $this->client($fixture),
        ], 'availability-b2b-conflict', 17);

        $projection = app(ListB2bSalesCallAvailability::class)->handle(
            client: $fixture['client'],
            dateFrom: '2026-08-31',
            dateTo: '2026-08-31',
            specialistId: $fixture['specialist']->getKey(),
            displayTimezone: 'UTC',
        );
        $starts = collect($projection['availability']['slots'])->pluck('startsAt');

        self::assertNotContains('2026-08-31T15:00:00+00:00', $starts);
        self::assertNotContains('2026-08-31T16:00:00+00:00', $starts);
        self::assertNotContains('2026-08-31T17:00:00+00:00', $starts);
        self::assertLessThanOrEqual((int) config('b2b.availability.max_slots'), $starts->count());

        $secondCall = $secondLead->salesCall()->firstOrFail();
        $cancelled = app(CancelB2bSalesCall::class)->handle(
            actor: $fixture['admin'],
            salesCall: $secondCall,
            expectedEventVersion: $secondCall->event_version,
        );
        $restored = app(ListB2bSalesCallAvailability::class)->handle(
            client: $fixture['client'],
            dateFrom: '2026-08-31',
            dateTo: '2026-08-31',
            specialistId: $fixture['specialist']->getKey(),
            displayTimezone: 'UTC',
        );

        self::assertSame(B2bSalesCallStatus::Cancelled, $cancelled->status);
        self::assertContains('2026-08-31T17:00:00+00:00', collect($restored['availability']['slots'])->pluck('startsAt'));
    }

    public function test_b2b_availability_is_bounded_and_keeps_specialists_tenant_scoped(): void
    {
        $fixture = $this->fixture();
        $otherSpecialist = Specialist::factory()->forOrganization($fixture['organization'])->create(['timezone' => 'UTC']);
        app(AssignSpecialistToService::class)->handle($fixture['admin'], $otherSpecialist, $fixture['service']);
        app(SetSpecialistWorkingHours::class)->handle($fixture['admin'], $otherSpecialist, [[
            'weekday' => 1,
            'start_time' => '09:00',
            'end_time' => '19:00',
        ]]);
        config()->set('b2b.availability.max_slots', 3);

        $projection = app(ListB2bSalesCallAvailability::class)->handle(
            client: $fixture['client'],
            dateFrom: '2026-08-31',
            dateTo: '2026-08-31',
            displayTimezone: 'UTC',
        );

        self::assertCount(2, $projection['specialists']);
        self::assertNull($projection['availability']);

        $selected = app(ListB2bSalesCallAvailability::class)->handle(
            client: $fixture['client'],
            dateFrom: '2026-08-31',
            dateTo: '2026-08-31',
            specialistId: $otherSpecialist->getKey(),
            displayTimezone: 'UTC',
        );
        self::assertCount(3, $selected['availability']['slots']);
        self::assertSame('2026-08-31T09:00:00+00:00', $selected['availability']['slots'][0]['startsAt']);

        $other = $this->fixture();
        $this->setOrganization($fixture['organization']);
        $tenantProjection = app(ListB2bSalesCallAvailability::class)->handle(
            client: $fixture['client'],
            dateFrom: '2026-08-31',
            dateTo: '2026-08-31',
            displayTimezone: 'UTC',
        );

        self::assertCount(2, $tenantProjection['specialists']);
        self::assertNotContains($other['specialist']->getKey(), array_column($tenantProjection['specialists'], 'id'));
    }

    public function test_b2b_availability_uses_client_timezone_and_dst_correctly(): void
    {
        $fixture = $this->fixture(specialistTimezone: 'UTC', clientTimezone: 'Asia/Almaty');
        $almaty = app(ListB2bSalesCallAvailability::class)->handle(
            client: $fixture['client'],
            dateFrom: '2026-08-31',
            dateTo: '2026-08-31',
            specialistId: $fixture['specialist']->getKey(),
        );

        self::assertSame('Asia/Almaty', $almaty['availability']['displayTimezone']);
        self::assertSame('2026-08-31T09:00:00+00:00', $almaty['availability']['slots'][0]['startsAt']);
        self::assertSame('2026-08-31T14:00:00+05:00', $almaty['availability']['slots'][0]['displayStartsAt']);

        $dstFixture = $this->fixture(
            specialistTimezone: 'America/New_York',
            clientTimezone: 'Europe/Berlin',
        );
        $dst = app(ListB2bSalesCallAvailability::class)->handle(
            client: $dstFixture['client'],
            dateFrom: '2026-11-02',
            dateTo: '2026-11-02',
            specialistId: $dstFixture['specialist']->getKey(),
        );

        self::assertSame('2026-11-02T14:00:00+00:00', $dst['availability']['slots'][0]['startsAt']);
        self::assertSame('2026-11-02T15:00:00+01:00', $dst['availability']['slots'][0]['displayStartsAt']);
    }

    public function test_portal_submission_preserves_the_second_dst_overlap_instant(): void
    {
        $fixture = $this->fixture(
            specialistTimezone: 'America/New_York',
            clientTimezone: 'America/New_York',
        );
        app(SetSpecialistWorkingHours::class)->handle($fixture['admin'], $fixture['specialist'], [[
            'weekday' => 7,
            'start_time' => '01:00',
            'end_time' => '03:00',
        ]]);
        app(SetOrganizationSetting::class)->handle(
            $fixture['admin'],
            OrganizationSettingKey::B2bSalesCallDurationMinutes,
            30,
        );

        $projection = app(ListB2bSalesCallAvailability::class)->handle(
            client: $fixture['client'],
            dateFrom: '2026-11-01',
            dateTo: '2026-11-01',
            specialistId: $fixture['specialist']->getKey(),
            displayTimezone: 'America/New_York',
        );
        $overlapSlots = collect($projection['availability']['slots'])
            ->filter(static fn (array $slot): bool => str_contains($slot['displayStartsAt'], 'T01:30:00'))
            ->values();

        self::assertCount(2, $overlapSlots);
        self::assertSame([
            '2026-11-01T05:30:00+00:00',
            '2026-11-01T06:30:00+00:00',
        ], $overlapSlots->pluck('startsAt')->all());
        self::assertSame(['-04:00', '-05:00'], $overlapSlots->pluck('displayUtcOffset')->all());

        $shortService = Service::factory()->forOrganization($fixture['organization'])->create([
            'duration_minutes' => 30,
            'buffer_minutes' => 0,
            'formats' => ['office'],
        ]);
        app(AssignSpecialistToService::class)->handle($fixture['admin'], $fixture['specialist'], $shortService);
        $bookingClient = $this->client($fixture);
        app(CreateBooking::class)->handle(
            actor: $bookingClient,
            client: $bookingClient,
            specialist: $fixture['specialist'],
            service: $shortService,
            startsAt: CarbonImmutable::parse('2026-11-01T05:30:00+00:00'),
            format: VisitFormat::Office,
            idempotencyKey: 'dst-first-occurrence-occupied',
        );

        $this->withSession(['client_portal.client_id' => $fixture['client']->getKey()])
            ->post(route('portal.b2b.submit'), [
                'specialist_id' => $fixture['specialist']->getKey(),
                'starts_at' => '2026-11-01T06:30:00+00:00',
                'submission_key' => 'dst-second-occurrence',
            ])
            ->assertRedirect(route('portal.b2b'));

        $call = B2bSalesCall::query()->where('client_id', $fixture['client']->getKey())->sole();
        self::assertSame('2026-11-01T06:30:00+00:00', $call->startsAtUtc()->toIso8601String());
        self::assertSame('2026-11-01T07:00:00+00:00', $call->endsAtUtc()->toIso8601String());
    }

    public function test_portal_submission_rejects_a_timezone_naive_schedule_identity(): void
    {
        $fixture = $this->fixture();

        $this->withSession(['client_portal.client_id' => $fixture['client']->getKey()])
            ->post(route('portal.b2b.submit'), [
                'specialist_id' => $fixture['specialist']->getKey(),
                'starts_at' => '2026-08-31T15:00',
                'submission_key' => 'naive-schedule-identity',
            ])
            ->assertSessionHasErrors('starts_at');

        self::assertSame(0, B2bSalesCall::query()->count());
    }

    public function test_stale_b2b_slot_display_is_revalidated_at_submission(): void
    {
        $fixture = $this->fixture();
        $projection = app(ListB2bSalesCallAvailability::class)->handle(
            client: $fixture['client'],
            dateFrom: '2026-08-31',
            dateTo: '2026-08-31',
            specialistId: $fixture['specialist']->getKey(),
            displayTimezone: 'UTC',
        );
        $slot = collect($projection['availability']['slots'])->firstWhere('startsAt', '2026-08-31T15:00:00+00:00');
        self::assertIsArray($slot);
        $bookingClient = $this->client($fixture);
        app(CreateBooking::class)->handle(
            actor: $bookingClient,
            client: $bookingClient,
            specialist: $fixture['specialist'],
            service: $fixture['service'],
            startsAt: CarbonImmutable::parse($slot['startsAt']),
            format: VisitFormat::Office,
            idempotencyKey: 'stale-b2b-slot-booking',
        );

        try {
            $this->submit($fixture, 'stale-b2b-slot');
            self::fail('A stale B2B availability slot was accepted.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('starts_at', $exception->errors());
        }
    }

    public function test_cancellation_releases_the_slot_and_reschedule_moves_the_typed_projection(): void
    {
        $fixture = $this->fixture();
        $first = $this->submit($fixture, 'reschedule-first');
        $call = $first->salesCall()->firstOrFail();
        $newStart = $this->slot(17);

        $rescheduled = app(RescheduleB2bSalesCall::class)->handle(
            actor: $fixture['admin'],
            salesCall: $call,
            newStartsAt: $newStart,
            requestedTimezone: 'UTC',
            expectedEventVersion: $call->event_version,
        );

        $occupancy = $rescheduled->occupancyPeriod()->firstOrFail();
        self::assertSame($newStart->toIso8601String(), CarbonImmutable::parse($occupancy->starts_at)->utc()->toIso8601String());
        self::assertSame($newStart->toIso8601String(), $rescheduled->startsAtUtc()->toIso8601String());

        $bookingClient = $this->client($fixture);
        app(CreateBooking::class)->handle(
            actor: $bookingClient,
            client: $bookingClient,
            specialist: $fixture['specialist'],
            service: $fixture['service'],
            startsAt: $this->slot(16),
            format: VisitFormat::Office,
            idempotencyKey: 'blocking-reschedule-booking',
        );

        try {
            app(RescheduleB2bSalesCall::class)->handle(
                actor: $fixture['admin'],
                salesCall: $rescheduled,
                newStartsAt: $this->slot(16),
                requestedTimezone: 'UTC',
                expectedEventVersion: $rescheduled->event_version,
            );
            self::fail('A reschedule into an occupied ordinary booking was accepted.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('starts_at', $exception->errors());
        }

        $second = $this->submit([
            ...$fixture,
            'client' => $this->client($fixture),
        ], 'old-slot-reused');
        self::assertSame($this->slot()->toIso8601String(), $second->salesCall()->firstOrFail()->startsAtUtc()->toIso8601String());

        $secondCall = $second->salesCall()->firstOrFail();
        $cancelled = app(CancelB2bSalesCall::class)->handle(
            actor: $fixture['admin'],
            salesCall: $secondCall,
            expectedEventVersion: $secondCall->event_version,
        );

        self::assertSame('cancelled', $cancelled->status->value);
        self::assertSame(0, UnavailablePeriod::query()->where('b2b_sales_call_id', $cancelled->getKey())->count());
    }

    public function test_manual_link_mode_reserves_time_without_provider_event_and_can_be_completed_later(): void
    {
        $fixture = $this->fixture();
        $lead = $this->submit(
            fixture: $fixture,
            key: 'manual-sales-call',
            meetingMode: VideoMeetingMode::Manual,
            manualMeetingUrl: 'https://meet.example.test/manual-initial',
        );
        $call = $lead->salesCall()->firstOrFail();

        self::assertSame(VideoMeetingSyncStatus::NotRequired, $call->provider_sync_status);
        self::assertSame(0, IntegrationEvent::query()->count());
        self::assertSame(1, UnavailablePeriod::query()->where('b2b_sales_call_id', $call->getKey())->count());

        $updated = app(SetB2bSalesCallMeetingMode::class)->handle(
            actor: $fixture['admin'],
            salesCall: $call,
            mode: VideoMeetingMode::Manual,
            manualMeetingUrl: 'https://meet.example.test/manual-call',
            expectedEventVersion: $call->event_version,
        );

        self::assertSame('https://meet.example.test/manual-call', $updated->manual_meeting_url);
        self::assertSame(VideoMeetingSyncStatus::NotRequired, $updated->provider_sync_status);
        self::assertSame(2, DB::table('scenario_events')->where('event_name', 'b2b.sales_call.ready')->count());
    }

    public function test_switching_a_ready_zoom_call_to_manual_preserves_the_current_manual_ready_generation_after_cancellation(): void
    {
        $provider = new FakeVideoMeetingProvider;
        $this->app->instance(VideoMeetingProvider::class, $provider);
        $fixture = $this->fixture();
        $lead = $this->submit($fixture, 'manual-transition');
        $call = $lead->salesCall()->firstOrFail();
        app(SyncB2bSalesCallProvider::class)->handle(IntegrationEvent::query()->sole()->getKey());
        $ready = $call->fresh();
        $correlationKey = $ready->provider_correlation_key;

        $manual = app(SetB2bSalesCallMeetingMode::class)->handle(
            actor: $fixture['admin'],
            salesCall: $ready,
            mode: VideoMeetingMode::Manual,
            manualMeetingUrl: 'https://meet.example.test/manual-transition',
            expectedEventVersion: $ready->event_version,
        );
        $cancelEvent = IntegrationEvent::query()->latest('id')->firstOrFail();
        app(SyncB2bSalesCallProvider::class)->handle($cancelEvent->getKey());

        $final = $manual->fresh();
        self::assertSame(VideoMeetingMode::Manual, $final->meeting_mode);
        self::assertSame(VideoMeetingSyncStatus::NotRequired, $final->provider_sync_status);
        self::assertSame($correlationKey, $final->provider_correlation_key);
        self::assertSame('https://meet.example.test/manual-transition', $final->manual_meeting_url);
    }

    public function test_manual_local_cancellation_preserves_ambiguous_provider_cleanup_until_the_current_event_finishes(): void
    {
        $provider = new FakeVideoMeetingProvider;
        $this->app->instance(VideoMeetingProvider::class, $provider);
        $fixture = $this->fixture();
        $lead = $this->submit($fixture, 'manual-ambiguous-cleanup');
        $call = $lead->salesCall()->firstOrFail();
        app(SyncB2bSalesCallProvider::class)->handle(IntegrationEvent::query()->sole()->getKey());
        $ready = $call->fresh();

        $manual = app(SetB2bSalesCallMeetingMode::class)->handle(
            actor: $fixture['admin'],
            salesCall: $ready,
            mode: VideoMeetingMode::Manual,
            manualMeetingUrl: 'https://meet.example.test/manual-ambiguous-cleanup',
            expectedEventVersion: $ready->event_version,
        );
        $manualCancelEvent = IntegrationEvent::query()->latest('id')->firstOrFail();
        $provider->throwAfterCancel = true;
        $provider->leaveMeetingAfterCancelFailure = true;

        app(SyncB2bSalesCallProvider::class)->handle($manualCancelEvent->getKey());

        $ambiguous = $call->fresh();
        self::assertSame(VideoMeetingMode::Manual, $ambiguous->meeting_mode);
        self::assertSame(VideoMeetingSyncStatus::ReconciliationRequired, $ambiguous->provider_sync_status);
        self::assertSame(VideoMeetingOperation::Cancel, $ambiguous->provider_operation);
        self::assertNotNull($ambiguous->provider_meeting_id);
        self::assertSame('retryable', $manualCancelEvent->fresh()->status->value);

        $cancelled = app(CancelB2bSalesCall::class)->handle(
            actor: $fixture['admin'],
            salesCall: $ambiguous,
            expectedEventVersion: $ambiguous->event_version,
        );
        $currentCancelEvent = IntegrationEvent::query()->latest('id')->firstOrFail();

        self::assertSame(B2bSalesCallStatus::Cancelled, $cancelled->status);
        self::assertSame(VideoMeetingSyncStatus::ReconciliationRequired, $cancelled->provider_sync_status);
        self::assertSame(VideoMeetingOperation::Cancel, $cancelled->provider_operation);
        self::assertNotSame($manualCancelEvent->getKey(), $currentCancelEvent->getKey());
        self::assertSame($cancelled->provider_sync_version, $currentCancelEvent->payload['provider_sync_version']);

        $manualCancelEvent->forceFill(['available_at' => now()->subSecond()])->save();
        app(SyncB2bSalesCallProvider::class)->handle($manualCancelEvent->getKey());

        self::assertSame('processed', $manualCancelEvent->fresh()->status->value);
        self::assertSame(VideoMeetingSyncStatus::ReconciliationRequired, $call->fresh()->provider_sync_status);
        self::assertSame(VideoMeetingOperation::Cancel, $call->fresh()->provider_operation);
        self::assertSame('pending', $currentCancelEvent->fresh()->status->value);

        app(SyncB2bSalesCallProvider::class)->handle($currentCancelEvent->getKey());

        $final = $call->fresh();
        self::assertSame(VideoMeetingSyncStatus::NotRequired, $final->provider_sync_status);
        self::assertNull($final->provider_operation);
        self::assertNull($final->provider_meeting_id);
        self::assertSame(2, $provider->cancelCount);
        self::assertSame('processed', $currentCancelEvent->fresh()->status->value);
    }

    public function test_manual_local_cancellation_completes_after_authoritative_remote_absence_without_a_second_delete(): void
    {
        $provider = new FakeVideoMeetingProvider;
        $this->app->instance(VideoMeetingProvider::class, $provider);
        $fixture = $this->fixture();
        $lead = $this->submit($fixture, 'manual-absent-cleanup');
        $call = $lead->salesCall()->firstOrFail();
        app(SyncB2bSalesCallProvider::class)->handle(IntegrationEvent::query()->sole()->getKey());
        $ready = $call->fresh();

        $manual = app(SetB2bSalesCallMeetingMode::class)->handle(
            actor: $fixture['admin'],
            salesCall: $ready,
            mode: VideoMeetingMode::Manual,
            manualMeetingUrl: 'https://meet.example.test/manual-absent-cleanup',
            expectedEventVersion: $ready->event_version,
        );
        $manualCancelEvent = IntegrationEvent::query()->latest('id')->firstOrFail();
        $provider->throwAfterCancel = true;
        $provider->leaveMeetingAfterCancelFailure = true;
        app(SyncB2bSalesCallProvider::class)->handle($manualCancelEvent->getKey());
        $ambiguous = $call->fresh();

        $cancelled = app(CancelB2bSalesCall::class)->handle(
            actor: $fixture['admin'],
            salesCall: $ambiguous,
            expectedEventVersion: $ambiguous->event_version,
        );
        $currentCancelEvent = IntegrationEvent::query()->latest('id')->firstOrFail();
        $provider->remoteMissingOnGet = true;

        app(SyncB2bSalesCallProvider::class)->handle($currentCancelEvent->getKey());

        $final = $call->fresh();
        self::assertSame(B2bSalesCallStatus::Cancelled, $cancelled->status);
        self::assertSame(VideoMeetingSyncStatus::NotRequired, $final->provider_sync_status);
        self::assertNull($final->provider_operation);
        self::assertNull($final->provider_meeting_id);
        self::assertSame(1, $provider->cancelCount);
        self::assertSame('processed', $currentCancelEvent->fresh()->status->value);
    }

    public function test_failed_zoom_provisioning_keeps_the_reserved_slot_and_retry_reconciles_without_duplicate_meeting(): void
    {
        $provider = new FakeVideoMeetingProvider;
        $provider->throwAfterCreate = true;
        $this->app->instance(VideoMeetingProvider::class, $provider);
        $fixture = $this->fixture();
        $lead = $this->submit($fixture, 'provider-timeout');
        $call = $lead->salesCall()->firstOrFail();
        $event = IntegrationEvent::query()->sole();

        app(SyncB2bSalesCallProvider::class)->handle($event->getKey());

        self::assertSame(VideoMeetingSyncStatus::ReconciliationRequired, $call->fresh()->provider_sync_status);
        self::assertSame(1, UnavailablePeriod::query()->where('b2b_sales_call_id', $call->getKey())->count());
        self::assertSame('retryable', $event->fresh()->status->value);

        $retried = app(RetryB2bSalesCallProvider::class)->handle($fixture['admin'], $call->fresh(), $call->fresh()->event_version);
        $retryEvent = IntegrationEvent::query()->latest('id')->firstOrFail();

        app(SyncB2bSalesCallProvider::class)->handle($retryEvent->getKey());
        $ready = $retried->fresh();

        self::assertSame(VideoMeetingSyncStatus::Ready, $ready->provider_sync_status);
        self::assertSame('https://zoom.example.test/join/zoom-1', $ready->provider_join_url);
        self::assertSame(1, $provider->createCount);
        self::assertSame(1, UnavailablePeriod::query()->where('b2b_sales_call_id', $call->getKey())->count());
        self::assertSame(1, DB::table('scenario_events')->where('event_name', 'b2b.sales_call.ready')->count());
    }

    public function test_zero_match_after_unknown_create_remains_reconciliation_required_without_a_second_create(): void
    {
        $provider = new FakeVideoMeetingProvider;
        $provider->throwAfterCreate = true;
        $provider->hideMeetingsFromSearch = true;
        $this->app->instance(VideoMeetingProvider::class, $provider);
        $fixture = $this->fixture();
        $lead = $this->submit($fixture, 'provider-zero-match');
        $call = $lead->salesCall()->firstOrFail();
        $event = IntegrationEvent::query()->sole();

        app(SyncB2bSalesCallProvider::class)->handle($event->getKey());
        $retry = app(RetryB2bSalesCallProvider::class)->handle(
            $fixture['admin'],
            $call->fresh(),
            $call->fresh()->event_version,
        );
        $retryEvent = IntegrationEvent::query()->latest('id')->firstOrFail();
        app(SyncB2bSalesCallProvider::class)->handle($retryEvent->getKey());

        self::assertSame(1, $provider->createCount);
        self::assertSame(VideoMeetingSyncStatus::ReconciliationRequired, $retry->fresh()->provider_sync_status);
        self::assertNull($retry->fresh()->provider_meeting_id);
    }

    public function test_malformed_zoom_list_token_cannot_adopt_a_matching_meeting_or_reach_create_or_ready(): void
    {
        $fixture = $this->fixture();
        $this->credential(
            $fixture['organization'],
            'zoom',
            (string) config('b2b.credential_name'),
            CredentialStatus::Active,
        );
        $lead = $this->submit($fixture, 'malformed-zoom-list-token');
        $call = $lead->salesCall()->firstOrFail();
        $createCalls = 0;

        Http::fake(function (Request $request) use (&$createCalls, $call) {
            if ($request->url() === (string) config('b2b.zoom.oauth_url')) {
                return Http::response(['access_token' => 'server-token'], 200);
            }

            if ($request->method() === 'GET' && str_contains($request->url(), '/users/')) {
                return Http::response([
                    'meetings' => [[
                        'id' => 123456,
                        'uuid' => 'meeting-uuid',
                        'agenda' => 'CHUKLOV-B2B:'.$call->provider_correlation_key,
                        'start_time' => '2026-08-31T15:00:00Z',
                        'duration' => 60,
                        'timezone' => 'UTC',
                        'join_url' => 'https://zoom.us/j/123456',
                    ]],
                    'next_page_token' => null,
                ], 200);
            }

            if ($request->method() === 'POST' && str_ends_with($request->url(), '/meetings')) {
                $createCalls++;

                return Http::response([
                    'id' => 123456,
                    'uuid' => 'meeting-uuid',
                    'join_url' => 'https://zoom.us/j/123456',
                ], 201);
            }

            return Http::response([], 404);
        });

        $event = IntegrationEvent::query()->sole();
        app(SyncB2bSalesCallProvider::class)->handle($event->getKey());

        $final = $call->fresh();
        self::assertSame(VideoMeetingSyncStatus::ReconciliationRequired, $final->provider_sync_status);
        self::assertSame(VideoMeetingOperation::Create, $final->provider_operation);
        self::assertSame('zoom_find_incomplete', $final->provider_error_code);
        self::assertNull($final->provider_meeting_id);
        self::assertNull($final->provider_join_url);
        self::assertSame(0, $createCalls);
        self::assertSame(0, DB::table('scenario_events')->where('event_name', 'b2b.sales_call.ready')->count());
        self::assertSame('failed', $event->fresh()->status->value);
    }

    public function test_malformed_zoom_list_entry_keeps_create_in_reconciliation_required_state(): void
    {
        $fixture = $this->fixture();
        $this->credential(
            $fixture['organization'],
            'zoom',
            (string) config('b2b.credential_name'),
            CredentialStatus::Active,
        );
        $lead = $this->submit($fixture, 'malformed-zoom-list-entry');
        $call = $lead->salesCall()->firstOrFail();
        $createCalls = 0;

        Http::fake(function (Request $request) use (&$createCalls) {
            if ($request->url() === (string) config('b2b.zoom.oauth_url')) {
                return Http::response(['access_token' => 'server-token'], 200);
            }

            if ($request->method() === 'GET' && str_contains($request->url(), '/users/')) {
                return Http::response([
                    'meetings' => [[
                        'id' => 'garbage',
                        'agenda' => 'Unrelated Zoom meeting',
                    ]],
                    'next_page_token' => '',
                ], 200);
            }

            if ($request->method() === 'POST' && str_ends_with($request->url(), '/meetings')) {
                $createCalls++;

                return Http::response([
                    'id' => 123456,
                    'uuid' => 'meeting-uuid',
                    'join_url' => 'https://zoom.us/j/123456',
                ], 201);
            }

            return Http::response([], 404);
        });

        $event = IntegrationEvent::query()->sole();
        app(SyncB2bSalesCallProvider::class)->handle($event->getKey());

        $final = $call->fresh();
        self::assertSame(VideoMeetingSyncStatus::ReconciliationRequired, $final->provider_sync_status);
        self::assertSame(VideoMeetingOperation::Create, $final->provider_operation);
        self::assertSame('zoom_find_incomplete', $final->provider_error_code);
        self::assertNull($final->provider_meeting_id);
        self::assertNull($final->provider_join_url);
        self::assertSame(0, $createCalls);
        self::assertSame(0, DB::table('scenario_events')->where('event_name', 'b2b.sales_call.ready')->count());
        self::assertSame('failed', $event->fresh()->status->value);
    }

    public function test_correlated_malformed_zoom_join_url_cannot_reach_create_or_ready(): void
    {
        $fixture = $this->fixture();
        $this->credential(
            $fixture['organization'],
            'zoom',
            (string) config('b2b.credential_name'),
            CredentialStatus::Active,
        );
        $lead = $this->submit($fixture, 'malformed-zoom-join-url');
        $call = $lead->salesCall()->firstOrFail();
        $createCalls = 0;

        Http::fake(function (Request $request) use (&$createCalls, $call) {
            if ($request->url() === (string) config('b2b.zoom.oauth_url')) {
                return Http::response(['access_token' => 'server-token'], 200);
            }

            if ($request->method() === 'GET' && str_contains($request->url(), '/users/')) {
                return Http::response([
                    'meetings' => [[
                        'id' => 123456,
                        'uuid' => 'meeting-uuid',
                        'agenda' => 'CHUKLOV-B2B:'.$call->provider_correlation_key,
                        'start_time' => '2026-08-31T15:00:00Z',
                        'duration' => 60,
                        'timezone' => 'UTC',
                        'join_url' => 'https://',
                    ]],
                    'next_page_token' => '',
                ], 200);
            }

            if ($request->method() === 'POST' && str_ends_with($request->url(), '/meetings')) {
                $createCalls++;
            }

            return Http::response([], 404);
        });

        $event = IntegrationEvent::query()->sole();
        app(SyncB2bSalesCallProvider::class)->handle($event->getKey());

        $final = $call->fresh();
        self::assertSame(VideoMeetingSyncStatus::ReconciliationRequired, $final->provider_sync_status);
        self::assertSame('zoom_find_incomplete', $final->provider_error_code);
        self::assertNull($final->provider_meeting_id);
        self::assertNull($final->provider_join_url);
        self::assertSame(0, $createCalls);
        self::assertSame(0, DB::table('scenario_events')->where('event_name', 'b2b.sales_call.ready')->count());
        self::assertSame('failed', $event->fresh()->status->value);
    }

    public function test_malformed_successful_zoom_create_response_cannot_reach_ready_or_trigger_a_second_create(): void
    {
        $fixture = $this->fixture();
        $this->credential(
            $fixture['organization'],
            'zoom',
            (string) config('b2b.credential_name'),
            CredentialStatus::Active,
        );
        $lead = $this->submit($fixture, 'malformed-zoom-create-response');
        $call = $lead->salesCall()->firstOrFail();
        $createCalls = 0;

        Http::fake(function (Request $request) use (&$createCalls, $call) {
            if ($request->url() === (string) config('b2b.zoom.oauth_url')) {
                return Http::response(['access_token' => 'server-token'], 200);
            }

            if ($request->method() === 'GET' && str_contains($request->url(), '/users/')) {
                return Http::response(['meetings' => [], 'next_page_token' => ''], 200);
            }

            if ($request->method() === 'POST' && str_ends_with($request->url(), '/meetings')) {
                $createCalls++;

                return Http::response([
                    'id' => 123456,
                    'uuid' => 'meeting-uuid',
                    'agenda' => 'CHUKLOV-B2B:'.$call->provider_correlation_key,
                    'start_time' => '2026-08-31T15:00:00Z',
                    'duration' => 60,
                    'timezone' => 'UTC',
                    'join_url' => 'https://',
                ], 201);
            }

            return Http::response([], 404);
        });

        $event = IntegrationEvent::query()->sole();
        app(SyncB2bSalesCallProvider::class)->handle($event->getKey());

        $final = $call->fresh();
        self::assertSame(VideoMeetingSyncStatus::ReconciliationRequired, $final->provider_sync_status);
        self::assertSame('zoom_meeting_response_invalid', $final->provider_error_code);
        self::assertNull($final->provider_meeting_id);
        self::assertNull($final->provider_join_url);
        self::assertSame(1, $createCalls);
        self::assertSame(0, DB::table('scenario_events')->where('event_name', 'b2b.sales_call.ready')->count());
        self::assertSame('failed', $event->fresh()->status->value);
    }

    public function test_update_uses_the_fresh_zoom_join_url_instead_of_a_cached_value(): void
    {
        $provider = new FakeVideoMeetingProvider;
        $this->app->instance(VideoMeetingProvider::class, $provider);
        $fixture = $this->fixture();
        $lead = $this->submit($fixture, 'cached-zoom-join-url');
        $call = $lead->salesCall()->firstOrFail();
        app(SyncB2bSalesCallProvider::class)->handle(IntegrationEvent::query()->sole()->getKey());

        $ready = $call->fresh();
        $rescheduled = app(RescheduleB2bSalesCall::class)->handle(
            actor: $fixture['admin'],
            salesCall: $ready,
            newStartsAt: $this->slot(17),
            requestedTimezone: 'UTC',
            expectedEventVersion: $ready->event_version,
        );
        $rescheduled->forceFill(['provider_join_url' => 'https://'])->save();
        $updateEvent = IntegrationEvent::query()->latest('id')->firstOrFail();

        app(SyncB2bSalesCallProvider::class)->handle($updateEvent->getKey());

        $final = $call->fresh();
        self::assertSame(VideoMeetingSyncStatus::Ready, $final->provider_sync_status);
        self::assertSame('https://zoom.example.test/join/zoom-1', $final->provider_join_url);
        self::assertSame(1, $provider->updateCount);
        self::assertSame('processed', $updateEvent->fresh()->status->value);
    }

    public function test_recreate_rejects_an_unresolved_unknown_create_without_rotating_generation(): void
    {
        $provider = new FakeVideoMeetingProvider;
        $provider->throwAfterCreate = true;
        $provider->hideMeetingsFromSearch = true;
        $this->app->instance(VideoMeetingProvider::class, $provider);
        $fixture = $this->fixture();
        $lead = $this->submit($fixture, 'provider-unresolved-recreate');
        $call = $lead->salesCall()->firstOrFail();
        app(SyncB2bSalesCallProvider::class)->handle(IntegrationEvent::query()->sole()->getKey());
        $unresolved = $call->fresh();
        $correlationKey = $unresolved->provider_correlation_key;
        $providerSyncVersion = $unresolved->provider_sync_version;
        $eventVersion = $unresolved->event_version;

        try {
            app(RecreateB2bSalesCallMeeting::class)->handle(
                actor: $fixture['admin'],
                salesCall: $unresolved,
                expectedEventVersion: $eventVersion,
            );
            self::fail('Recreate was allowed to discard an unresolved provider generation.');
        } catch (ValidationException $exception) {
            self::assertSame(
                'The current Zoom generation must be reconciled before the meeting can be recreated.',
                $exception->errors()['provider'][0],
            );
        }

        $final = $call->fresh();
        self::assertSame(VideoMeetingSyncStatus::ReconciliationRequired, $final->provider_sync_status);
        self::assertNull($final->provider_meeting_id);
        self::assertSame($correlationKey, $final->provider_correlation_key);
        self::assertSame($providerSyncVersion, $final->provider_sync_version);
        self::assertSame($eventVersion, $final->event_version);
        self::assertSame(1, $provider->createCount);
        self::assertSame(1, IntegrationEvent::query()->count());
    }

    public function test_cancelling_after_unknown_create_with_zero_match_remains_reconciliation_required(): void
    {
        $provider = new FakeVideoMeetingProvider;
        $provider->throwAfterCreate = true;
        $provider->hideMeetingsFromSearch = true;
        $this->app->instance(VideoMeetingProvider::class, $provider);
        $fixture = $this->fixture();
        $lead = $this->submit($fixture, 'provider-zero-match-cancel');
        $call = $lead->salesCall()->firstOrFail();

        app(SyncB2bSalesCallProvider::class)->handle(IntegrationEvent::query()->sole()->getKey());
        $cancelled = app(CancelB2bSalesCall::class)->handle(
            actor: $fixture['admin'],
            salesCall: $call->fresh(),
            expectedEventVersion: $call->fresh()->event_version,
        );
        $cancelEvent = IntegrationEvent::query()->latest('id')->firstOrFail();

        self::assertSame(VideoMeetingSyncStatus::ReconciliationRequired, $cancelled->provider_sync_status);

        app(SyncB2bSalesCallProvider::class)->handle($cancelEvent->getKey());

        self::assertSame(1, $provider->createCount);
        self::assertSame(0, $provider->cancelCount);
        self::assertSame(VideoMeetingSyncStatus::ReconciliationRequired, $call->fresh()->provider_sync_status);
        self::assertSame('failed', $cancelEvent->fresh()->status->value);
    }

    public function test_update_timeout_is_reconciled_without_creating_a_replacement_meeting(): void
    {
        $provider = new FakeVideoMeetingProvider;
        $this->app->instance(VideoMeetingProvider::class, $provider);
        $fixture = $this->fixture();
        $lead = $this->submit($fixture, 'provider-update-timeout');
        $call = $lead->salesCall()->firstOrFail();
        app(SyncB2bSalesCallProvider::class)->handle(IntegrationEvent::query()->sole()->getKey());
        $ready = $call->fresh();
        $provider->throwAfterUpdate = true;
        $rescheduled = app(RescheduleB2bSalesCall::class)->handle(
            $fixture['admin'],
            $ready,
            $this->slot(17),
            'UTC',
            $ready->event_version,
        );
        $updateEvent = IntegrationEvent::query()->latest('id')->firstOrFail();

        app(SyncB2bSalesCallProvider::class)->handle($updateEvent->getKey());

        self::assertSame(1, $provider->createCount);
        self::assertSame(1, $provider->updateCount);
        self::assertSame(VideoMeetingSyncStatus::Ready, $rescheduled->fresh()->provider_sync_status);
        self::assertSame('zoom-1', $rescheduled->fresh()->provider_meeting_id);
    }

    public function test_create_start_time_mismatch_cannot_become_ready(): void
    {
        $provider = new FakeVideoMeetingProvider;
        $provider->createdStartsAtOverride = $this->slot(16);
        $this->app->instance(VideoMeetingProvider::class, $provider);
        $fixture = $this->fixture();
        $lead = $this->submit($fixture, 'provider-schedule-start-mismatch');
        $call = $lead->salesCall()->firstOrFail();
        $event = IntegrationEvent::query()->sole();

        app(SyncB2bSalesCallProvider::class)->handle($event->getKey());

        $final = $call->fresh();
        self::assertSame(VideoMeetingSyncStatus::ReconciliationRequired, $final->provider_sync_status);
        self::assertSame('zoom_schedule_mismatch', $final->provider_error_code);
        self::assertNull($final->provider_meeting_id);
        self::assertSame(1, $provider->createCount);
        self::assertSame('failed', $event->fresh()->status->value);
        self::assertSame(0, DB::table('scenario_events')->where('event_name', 'b2b.sales_call.ready')->count());
    }

    public function test_create_duration_mismatch_cannot_become_ready(): void
    {
        $provider = new FakeVideoMeetingProvider;
        $provider->createdDurationOverride = 45;
        $this->app->instance(VideoMeetingProvider::class, $provider);
        $fixture = $this->fixture();
        $lead = $this->submit($fixture, 'provider-schedule-duration-mismatch');
        $call = $lead->salesCall()->firstOrFail();
        $event = IntegrationEvent::query()->sole();

        app(SyncB2bSalesCallProvider::class)->handle($event->getKey());

        $final = $call->fresh();
        self::assertSame(VideoMeetingSyncStatus::ReconciliationRequired, $final->provider_sync_status);
        self::assertSame('zoom_schedule_mismatch', $final->provider_error_code);
        self::assertNull($final->provider_meeting_id);
        self::assertSame(1, $provider->createCount);
        self::assertSame('failed', $event->fresh()->status->value);
    }

    public function test_create_timezone_mismatch_cannot_become_ready(): void
    {
        $provider = new FakeVideoMeetingProvider;
        $provider->createdTimezoneOverride = 'Asia/Almaty';
        $this->app->instance(VideoMeetingProvider::class, $provider);
        $fixture = $this->fixture();
        $lead = $this->submit($fixture, 'provider-schedule-timezone-mismatch');
        $call = $lead->salesCall()->firstOrFail();
        $event = IntegrationEvent::query()->sole();

        app(SyncB2bSalesCallProvider::class)->handle($event->getKey());

        $final = $call->fresh();
        self::assertSame(VideoMeetingSyncStatus::ReconciliationRequired, $final->provider_sync_status);
        self::assertSame('zoom_schedule_mismatch', $final->provider_error_code);
        self::assertNull($final->provider_meeting_id);
        self::assertSame(1, $provider->createCount);
        self::assertSame('failed', $event->fresh()->status->value);
    }

    public function test_unknown_create_recovery_repairs_schedule_before_ready(): void
    {
        $provider = new FakeVideoMeetingProvider;
        $provider->createdStartsAtOverride = $this->slot(16);
        $provider->throwAfterCreate = true;
        $this->app->instance(VideoMeetingProvider::class, $provider);
        $fixture = $this->fixture();
        $lead = $this->submit($fixture, 'provider-schedule-recovery');
        $call = $lead->salesCall()->firstOrFail();
        $createEvent = IntegrationEvent::query()->sole();
        app(SyncB2bSalesCallProvider::class)->handle($createEvent->getKey());
        $unknown = $call->fresh();

        $retry = app(RetryB2bSalesCallProvider::class)->handle(
            $fixture['admin'],
            $unknown,
            $unknown->event_version,
        );
        $retryEvent = IntegrationEvent::query()->latest('id')->firstOrFail();
        app(SyncB2bSalesCallProvider::class)->handle($retryEvent->getKey());

        $final = $call->fresh();
        self::assertSame(VideoMeetingSyncStatus::ReconciliationRequired, $unknown->provider_sync_status);
        self::assertSame(VideoMeetingSyncStatus::Ready, $final->provider_sync_status);
        self::assertSame(1, $provider->createCount);
        self::assertSame(1, $provider->updateCount);
        self::assertSame('zoom-1', $final->provider_meeting_id);
        self::assertNull($final->provider_error_code);
        self::assertSame(VideoMeetingSyncStatus::ReconciliationRequired, $retry->provider_sync_status);
    }

    public function test_known_identity_reconciliation_repairs_schedule_before_ready(): void
    {
        $provider = new FakeVideoMeetingProvider;
        $this->app->instance(VideoMeetingProvider::class, $provider);
        $fixture = $this->fixture();
        $lead = $this->submit($fixture, 'provider-schedule-reconcile');
        $call = $lead->salesCall()->firstOrFail();
        app(SyncB2bSalesCallProvider::class)->handle(IntegrationEvent::query()->sole()->getKey());
        $ready = $call->fresh();
        $identity = $ready->providerIdentity();
        self::assertInstanceOf(VideoMeetingIdentity::class, $identity);

        $reconciliation = app(MarkB2bSalesCallProviderReconciliationRequired::class)->handle(
            actor: $fixture['admin'],
            salesCall: $ready,
            identity: $identity,
            errorCode: 'zoom_get_failed',
            expectedEventVersion: $ready->event_version,
            expectedProviderSyncVersion: $ready->provider_sync_version,
        );
        $event = IntegrationEvent::query()->latest('id')->firstOrFail();
        $provider->remoteMeetingOverride = $this->remoteMeetingWithSchedule(
            $identity,
            (string) $ready->provider_correlation_key,
            $this->slot(16),
            60,
            'UTC',
        );

        app(SyncB2bSalesCallProvider::class)->handle($event->getKey());

        $final = $call->fresh();
        self::assertSame(VideoMeetingSyncStatus::ReconciliationRequired, $reconciliation->provider_sync_status);
        self::assertSame(VideoMeetingSyncStatus::Ready, $final->provider_sync_status);
        self::assertSame(1, $provider->updateCount);
        self::assertSame('zoom-1', $final->provider_meeting_id);
        self::assertNull($final->provider_error_code);
    }

    public function test_update_that_leaves_the_remote_schedule_mismatched_cannot_become_ready(): void
    {
        $provider = new FakeVideoMeetingProvider;
        $provider->leaveScheduleMismatchedAfterUpdate = true;
        $this->app->instance(VideoMeetingProvider::class, $provider);
        $fixture = $this->fixture();
        $lead = $this->submit($fixture, 'provider-schedule-post-patch-mismatch');
        $call = $lead->salesCall()->firstOrFail();
        app(SyncB2bSalesCallProvider::class)->handle(IntegrationEvent::query()->sole()->getKey());
        $ready = $call->fresh();
        $rescheduled = app(RescheduleB2bSalesCall::class)->handle(
            $fixture['admin'],
            $ready,
            $this->slot(17),
            'UTC',
            $ready->event_version,
        );
        $event = IntegrationEvent::query()->latest('id')->firstOrFail();

        app(SyncB2bSalesCallProvider::class)->handle($event->getKey());

        $final = $call->fresh();
        self::assertSame(VideoMeetingSyncStatus::ReconciliationRequired, $final->provider_sync_status);
        self::assertSame(VideoMeetingOperation::Update, $final->provider_operation);
        self::assertSame('zoom_schedule_mismatch', $final->provider_error_code);
        self::assertSame(1, $provider->updateCount);
        self::assertSame(1, $provider->createCount);
        self::assertSame('zoom-1', $rescheduled->provider_meeting_id);
        self::assertNull($final->provider_join_url);
        self::assertSame('failed', $event->fresh()->status->value);
    }

    public function test_cancel_timeout_then_remote_404_reconciles_as_cancelled_without_repeating_delete(): void
    {
        $provider = new FakeVideoMeetingProvider;
        $this->app->instance(VideoMeetingProvider::class, $provider);
        $fixture = $this->fixture();
        $lead = $this->submit($fixture, 'provider-cancel-timeout');
        $call = $lead->salesCall()->firstOrFail();
        app(SyncB2bSalesCallProvider::class)->handle(IntegrationEvent::query()->sole()->getKey());
        $ready = $call->fresh();
        $provider->throwAfterCancel = true;
        $cancelled = app(CancelB2bSalesCall::class)->handle(
            $fixture['admin'],
            $ready,
            $ready->event_version,
        );
        $cancelEvent = IntegrationEvent::query()->latest('id')->firstOrFail();
        app(SyncB2bSalesCallProvider::class)->handle($cancelEvent->getKey());

        $retry = app(RetryB2bSalesCallProvider::class)->handle(
            $fixture['admin'],
            $cancelled->fresh(),
            $cancelled->fresh()->event_version,
        );
        $retryEvent = IntegrationEvent::query()->latest('id')->firstOrFail();
        app(SyncB2bSalesCallProvider::class)->handle($retryEvent->getKey());

        self::assertSame(1, $provider->cancelCount);
        self::assertSame(VideoMeetingSyncStatus::NotRequired, $retry->fresh()->provider_sync_status);
        self::assertNull($retry->fresh()->provider_meeting_id);
    }

    public function test_stale_known_provider_id_requires_reconciliation_and_never_blindly_creates(): void
    {
        $provider = new FakeVideoMeetingProvider;
        $this->app->instance(VideoMeetingProvider::class, $provider);
        $fixture = $this->fixture();
        $lead = $this->submit($fixture, 'provider-stale-id');
        $call = $lead->salesCall()->firstOrFail();
        app(SyncB2bSalesCallProvider::class)->handle(IntegrationEvent::query()->sole()->getKey());
        $ready = $call->fresh();
        $provider->throwOnUpdate = true;
        $provider->remoteMissingOnGet = true;
        $rescheduled = app(RescheduleB2bSalesCall::class)->handle(
            $fixture['admin'],
            $ready,
            $this->slot(17),
            'UTC',
            $ready->event_version,
        );
        $updateEvent = IntegrationEvent::query()->latest('id')->firstOrFail();

        app(SyncB2bSalesCallProvider::class)->handle($updateEvent->getKey());

        self::assertSame(1, $provider->createCount);
        self::assertSame(VideoMeetingSyncStatus::ReconciliationRequired, $rescheduled->fresh()->provider_sync_status);
        self::assertSame('zoom_update_identity_missing', $rescheduled->fresh()->provider_error_code);
    }

    public function test_reschedule_after_reconciliation_rotates_the_new_provider_generation(): void
    {
        $provider = new FakeVideoMeetingProvider;
        $this->app->instance(VideoMeetingProvider::class, $provider);
        $fixture = $this->fixture();
        $lead = $this->submit($fixture, 'provider-recreate-after-reconciliation');
        $call = $lead->salesCall()->firstOrFail();
        app(SyncB2bSalesCallProvider::class)->handle(IntegrationEvent::query()->sole()->getKey());

        $ready = $call->fresh();
        $identity = $ready->providerIdentity();
        self::assertInstanceOf(VideoMeetingIdentity::class, $identity);
        $oldMeetingId = $identity->meetingId;
        $oldCorrelationKey = (string) $ready->provider_correlation_key;

        $reconciled = app(MarkB2bSalesCallProviderReconciliationRequired::class)->handle(
            actor: $fixture['admin'],
            salesCall: $ready,
            identity: $identity,
            errorCode: 'zoom_update_identity_missing',
            expectedEventVersion: $ready->event_version,
            expectedProviderSyncVersion: $ready->provider_sync_version,
        );
        $reconciled = $reconciled->fresh();

        self::assertSame($oldMeetingId, $reconciled->provider_meeting_id);
        self::assertSame($oldCorrelationKey, $reconciled->provider_correlation_key);
        self::assertNull($reconciled->provider_join_url);
        self::assertNull($reconciled->provider_recreate_meeting_id);
        self::assertNull($reconciled->provider_recreate_correlation_key);

        $rescheduled = app(RescheduleB2bSalesCall::class)->handle(
            $fixture['admin'],
            $reconciled,
            $this->slot(17),
            'UTC',
            $reconciled->event_version,
        );
        $recreateEvent = IntegrationEvent::query()->latest('id')->firstOrFail();
        $recreating = $rescheduled->fresh();
        $newCorrelationKey = (string) $recreating->provider_correlation_key;

        self::assertSame(VideoMeetingOperation::Recreate, $recreating->provider_operation);
        self::assertSame(VideoMeetingSyncStatus::ReconciliationRequired, $recreating->provider_sync_status);
        self::assertSame($oldMeetingId, $recreating->provider_recreate_meeting_id);
        self::assertSame($oldCorrelationKey, $recreating->provider_recreate_correlation_key);
        self::assertNotSame('', $newCorrelationKey);
        self::assertNotSame($oldCorrelationKey, $newCorrelationKey);

        app(SyncB2bSalesCallProvider::class)->handle($recreateEvent->getKey());

        $afterCleanup = $call->fresh();
        $createEvent = IntegrationEvent::query()->latest('id')->firstOrFail();
        self::assertSame(VideoMeetingOperation::Create, $afterCleanup->provider_operation);
        self::assertSame(VideoMeetingSyncStatus::Pending, $afterCleanup->provider_sync_status);
        self::assertNull($afterCleanup->provider_meeting_id);
        self::assertNull($afterCleanup->provider_recreate_meeting_id);
        self::assertNull($afterCleanup->provider_recreate_correlation_key);
        self::assertSame($newCorrelationKey, $afterCleanup->provider_correlation_key);

        $provider->searchMeetingOverride = $this->remoteMeeting(
            new VideoMeetingIdentity('stale-meeting', 'stale-uuid'),
            $oldCorrelationKey,
        );
        app(SyncB2bSalesCallProvider::class)->handle($createEvent->getKey());

        $final = $call->fresh();
        self::assertSame(VideoMeetingSyncStatus::Ready, $final->provider_sync_status);
        self::assertSame($newCorrelationKey, $final->provider_correlation_key);
        self::assertSame('zoom-2', $final->provider_meeting_id);
        self::assertNotSame('stale-meeting', $final->provider_meeting_id);
        self::assertSame($newCorrelationKey, $provider->lastRequest?->externalKey);
    }

    public function test_reconciliation_rejects_a_remote_identity_mismatch_without_adopting_it(): void
    {
        $provider = new FakeVideoMeetingProvider;
        $this->app->instance(VideoMeetingProvider::class, $provider);
        $fixture = $this->fixture();
        $lead = $this->submit($fixture, 'provider-reconcile-identity-mismatch');
        $call = $lead->salesCall()->firstOrFail();
        app(SyncB2bSalesCallProvider::class)->handle(IntegrationEvent::query()->sole()->getKey());
        $ready = $call->fresh();
        $identity = $ready->providerIdentity();
        self::assertInstanceOf(VideoMeetingIdentity::class, $identity);

        $reconciliation = app(MarkB2bSalesCallProviderReconciliationRequired::class)->handle(
            actor: $fixture['admin'],
            salesCall: $ready,
            identity: $identity,
            errorCode: 'zoom_get_failed',
            expectedEventVersion: $ready->event_version,
            expectedProviderSyncVersion: $ready->provider_sync_version,
        );
        $event = IntegrationEvent::query()->latest('id')->firstOrFail();
        $provider->remoteMeetingOverride = $this->remoteMeeting(
            new VideoMeetingIdentity('foreign-meeting', 'foreign-uuid'),
            (string) $ready->provider_correlation_key,
        );

        app(SyncB2bSalesCallProvider::class)->handle($event->getKey());

        $final = $call->fresh();
        self::assertSame(VideoMeetingSyncStatus::ReconciliationRequired, $reconciliation->provider_sync_status);
        self::assertSame(VideoMeetingOperation::Reconcile, $final->provider_operation);
        self::assertSame(VideoMeetingSyncStatus::ReconciliationRequired, $final->provider_sync_status);
        self::assertSame('zoom_meeting_identity_mismatch', $final->provider_error_code);
        self::assertSame('zoom-1', $final->provider_meeting_id);
        self::assertSame('uuid-1', $final->provider_meeting_uuid);
        self::assertSame('failed', $event->fresh()->status->value);
    }

    public function test_recreate_does_not_delete_an_old_remote_meeting_when_identity_correlation_is_unproven(): void
    {
        $provider = new FakeVideoMeetingProvider;
        $this->app->instance(VideoMeetingProvider::class, $provider);
        $fixture = $this->fixture();
        $lead = $this->submit($fixture, 'provider-recreate-identity-mismatch');
        $call = $lead->salesCall()->firstOrFail();
        app(SyncB2bSalesCallProvider::class)->handle(IntegrationEvent::query()->sole()->getKey());
        $ready = $call->fresh();
        $oldCorrelationKey = (string) $ready->provider_correlation_key;

        $recreating = app(RecreateB2bSalesCallMeeting::class)->handle(
            actor: $fixture['admin'],
            salesCall: $ready,
            expectedEventVersion: $ready->event_version,
        );
        $event = IntegrationEvent::query()->latest('id')->firstOrFail();
        $provider->remoteMeetingOverride = $this->remoteMeeting(
            new VideoMeetingIdentity('foreign-meeting', 'foreign-uuid'),
            $oldCorrelationKey,
        );

        app(SyncB2bSalesCallProvider::class)->handle($event->getKey());

        $final = $call->fresh();
        self::assertNotSame($oldCorrelationKey, $recreating->provider_correlation_key);
        self::assertSame(VideoMeetingSyncStatus::ReconciliationRequired, $final->provider_sync_status);
        self::assertSame(VideoMeetingOperation::Recreate, $final->provider_operation);
        self::assertSame('zoom_meeting_identity_mismatch', $final->provider_error_code);
        self::assertSame('zoom-1', $final->provider_meeting_id);
        self::assertSame('zoom-1', $final->provider_recreate_meeting_id);
        self::assertSame($oldCorrelationKey, $final->provider_recreate_correlation_key);
        self::assertSame(1, $provider->createCount);
        self::assertSame(0, $provider->cancelCount);
    }

    public function test_reconciliation_clears_the_recreate_identity_and_correlation_pair_together(): void
    {
        $provider = new FakeVideoMeetingProvider;
        $this->app->instance(VideoMeetingProvider::class, $provider);
        $fixture = $this->fixture();
        $lead = $this->submit($fixture, 'provider-recreate-pair-clear');
        $call = $lead->salesCall()->firstOrFail();
        app(SyncB2bSalesCallProvider::class)->handle(IntegrationEvent::query()->sole()->getKey());
        $ready = $call->fresh();
        $identity = $ready->providerIdentity();
        self::assertInstanceOf(VideoMeetingIdentity::class, $identity);

        $recreating = app(RecreateB2bSalesCallMeeting::class)->handle(
            $fixture['admin'],
            $ready,
            $ready->event_version,
        );
        self::assertNotNull($recreating->provider_recreate_meeting_id);
        self::assertNotNull($recreating->provider_recreate_correlation_key);

        $reconciled = app(MarkB2bSalesCallProviderReconciliationRequired::class)->handle(
            actor: $fixture['admin'],
            salesCall: $recreating,
            identity: $identity,
            errorCode: 'zoom_recreate_pair_invalid',
            expectedEventVersion: $recreating->event_version,
            expectedProviderSyncVersion: $recreating->provider_sync_version,
        );

        self::assertSame(VideoMeetingSyncStatus::ReconciliationRequired, $reconciled->provider_sync_status);
        self::assertNull($reconciled->provider_recreate_meeting_id);
        self::assertNull($reconciled->provider_recreate_correlation_key);
    }

    public function test_host_launch_rejects_a_remote_correlation_mismatch_without_returning_its_start_url(): void
    {
        $provider = new FakeVideoMeetingProvider;
        $this->app->instance(VideoMeetingProvider::class, $provider);
        $fixture = $this->fixture();
        $lead = $this->submit($fixture, 'provider-host-correlation-mismatch');
        $call = $lead->salesCall()->firstOrFail();
        app(SyncB2bSalesCallProvider::class)->handle(IntegrationEvent::query()->sole()->getKey());
        $ready = $call->fresh();
        $identity = $ready->providerIdentity();
        self::assertInstanceOf(VideoMeetingIdentity::class, $identity);
        $provider->remoteMeetingOverride = $this->remoteMeeting($identity, 'foreign-correlation');

        try {
            app(GetB2bSalesCallHostLaunchUrl::class)->handle($fixture['admin'], $ready);
            self::fail('A host URL was returned for a remote correlation mismatch.');
        } catch (ValidationException $exception) {
            self::assertSame(
                'The Zoom meeting is no longer available. Reconcile or recreate it before launching.',
                $exception->errors()['provider'][0],
            );
        }

        $final = $call->fresh();
        self::assertSame(1, $provider->hostLaunchCount);
        self::assertSame(VideoMeetingSyncStatus::ReconciliationRequired, $final->provider_sync_status);
        self::assertSame(VideoMeetingOperation::Reconcile, $final->provider_operation);
        self::assertNull($final->provider_join_url);
        self::assertSame('zoom-1', $final->provider_meeting_id);
    }

    public function test_host_launch_rejects_a_remote_schedule_mismatch_without_returning_its_start_url(): void
    {
        $provider = new FakeVideoMeetingProvider;
        $this->app->instance(VideoMeetingProvider::class, $provider);
        $fixture = $this->fixture();
        $lead = $this->submit($fixture, 'provider-host-schedule-mismatch');
        $call = $lead->salesCall()->firstOrFail();
        app(SyncB2bSalesCallProvider::class)->handle(IntegrationEvent::query()->sole()->getKey());
        $ready = $call->fresh();
        $identity = $ready->providerIdentity();
        self::assertInstanceOf(VideoMeetingIdentity::class, $identity);
        $provider->remoteMeetingOverride = $this->remoteMeetingWithSchedule(
            $identity,
            (string) $ready->provider_correlation_key,
            $this->slot(16),
            60,
            'UTC',
        );

        try {
            app(GetB2bSalesCallHostLaunchUrl::class)->handle($fixture['admin'], $ready);
            self::fail('A host URL was returned for a remote schedule mismatch.');
        } catch (ValidationException $exception) {
            self::assertSame(
                'The Zoom meeting is no longer available. Reconcile or recreate it before launching.',
                $exception->errors()['provider'][0],
            );
        }

        $final = $call->fresh();
        self::assertSame(1, $provider->hostLaunchCount);
        self::assertSame(VideoMeetingSyncStatus::ReconciliationRequired, $final->provider_sync_status);
        self::assertSame(VideoMeetingOperation::Reconcile, $final->provider_operation);
        self::assertSame('zoom_schedule_mismatch', $final->provider_error_code);
        self::assertNull($final->provider_join_url);
    }

    public function test_recreate_assigns_a_new_correlation_generation_and_cancels_the_old_identity_first(): void
    {
        $provider = new FakeVideoMeetingProvider;
        $this->app->instance(VideoMeetingProvider::class, $provider);
        $fixture = $this->fixture();
        $lead = $this->submit($fixture, 'provider-recreate-generation');
        $call = $lead->salesCall()->firstOrFail();
        app(SyncB2bSalesCallProvider::class)->handle(IntegrationEvent::query()->sole()->getKey());
        $ready = $call->fresh();
        $oldCorrelationKey = $ready->provider_correlation_key;

        $recreated = app(RecreateB2bSalesCallMeeting::class)->handle(
            $fixture['admin'],
            $ready,
            $ready->event_version,
        );
        $recreateEvent = IntegrationEvent::query()->latest('id')->firstOrFail();
        $recreatingState = $recreated->fresh();
        self::assertSame($ready->provider_meeting_id, $recreatingState->provider_recreate_meeting_id);
        self::assertSame($oldCorrelationKey, $recreatingState->provider_recreate_correlation_key);
        $recreateProviderSyncVersion = $recreatingState->provider_sync_version;
        $recreateEventVersion = $recreatingState->event_version;
        app(SyncB2bSalesCallProvider::class)->handle($recreateEvent->getKey());
        $createEvent = IntegrationEvent::query()->latest('id')->firstOrFail();
        $afterCleanup = $recreated->fresh();
        self::assertNotSame($oldCorrelationKey, $afterCleanup->provider_correlation_key);
        self::assertSame(VideoMeetingOperation::Create, $afterCleanup->provider_operation);
        self::assertSame(VideoMeetingSyncStatus::Pending, $afterCleanup->provider_sync_status);
        self::assertSame($recreateProviderSyncVersion + 1, $afterCleanup->provider_sync_version);
        self::assertSame($recreateEventVersion + 1, $afterCleanup->event_version);
        self::assertNull($afterCleanup->provider_meeting_id);
        self::assertNull($afterCleanup->provider_recreate_meeting_id);
        self::assertNull($afterCleanup->provider_recreate_correlation_key);
        self::assertSame(VideoMeetingOperation::Create->value, $createEvent->payload['operation']);
        self::assertSame($afterCleanup->provider_sync_version, $createEvent->payload['provider_sync_version']);
        self::assertSame($afterCleanup->event_version, $createEvent->payload['event_version']);
        self::assertSame('processed', $recreateEvent->fresh()->status->value);
        self::assertSame(1, $provider->createCount);
        self::assertSame(1, $provider->cancelCount);

        app(SyncB2bSalesCallProvider::class)->handle($recreateEvent->getKey());

        self::assertSame(1, $provider->createCount);

        app(SyncB2bSalesCallProvider::class)->handle($createEvent->getKey());

        self::assertSame(2, $provider->createCount);
        self::assertSame('zoom-2', $recreated->fresh()->provider_meeting_id);
        self::assertSame(VideoMeetingSyncStatus::Ready, $recreated->fresh()->provider_sync_status);
    }

    public function test_recreate_does_not_create_when_old_cleanup_is_ambiguous(): void
    {
        $provider = new FakeVideoMeetingProvider;
        $this->app->instance(VideoMeetingProvider::class, $provider);
        $fixture = $this->fixture();
        $lead = $this->submit($fixture, 'provider-recreate-ambiguous-cleanup');
        $call = $lead->salesCall()->firstOrFail();
        app(SyncB2bSalesCallProvider::class)->handle(IntegrationEvent::query()->sole()->getKey());
        $ready = $call->fresh();
        $oldMeetingId = $ready->provider_meeting_id;
        $oldCorrelationKey = $ready->provider_correlation_key;

        $recreating = app(RecreateB2bSalesCallMeeting::class)->handle(
            $fixture['admin'],
            $ready,
            $ready->event_version,
        );
        $recreateEvent = IntegrationEvent::query()->latest('id')->firstOrFail();
        $provider->throwAfterCancel = true;
        $provider->leaveMeetingAfterCancelFailure = true;

        app(SyncB2bSalesCallProvider::class)->handle($recreateEvent->getKey());

        $ambiguous = $call->fresh();
        self::assertSame(VideoMeetingSyncStatus::ReconciliationRequired, $ambiguous->provider_sync_status);
        self::assertSame(VideoMeetingOperation::Recreate, $ambiguous->provider_operation);
        self::assertSame($oldMeetingId, $ambiguous->provider_meeting_id);
        self::assertSame($oldMeetingId, $ambiguous->provider_recreate_meeting_id);
        self::assertSame($oldCorrelationKey, $ambiguous->provider_recreate_correlation_key);
        self::assertNotSame($oldCorrelationKey, $ambiguous->provider_correlation_key);
        self::assertSame(1, $provider->createCount);
        self::assertSame(1, $provider->cancelCount);
        self::assertSame('retryable', $recreateEvent->fresh()->status->value);

        $retry = app(RetryB2bSalesCallProvider::class)->handle(
            $fixture['admin'],
            $recreating->fresh(),
            $ambiguous->event_version,
        );

        self::assertSame(VideoMeetingOperation::Recreate, $retry->provider_operation);
        self::assertSame($oldMeetingId, $retry->provider_recreate_meeting_id);
        self::assertSame($oldCorrelationKey, $retry->provider_recreate_correlation_key);
        self::assertSame(VideoMeetingSyncStatus::ReconciliationRequired, $retry->provider_sync_status);
        self::assertNotSame(VideoMeetingSyncStatus::NotRequired, $retry->provider_sync_status);
        self::assertSame(1, $provider->createCount);
    }

    public function test_recreate_old_authoritative_404_transitions_to_create_and_fences_the_old_event(): void
    {
        $provider = new FakeVideoMeetingProvider;
        $this->app->instance(VideoMeetingProvider::class, $provider);
        $fixture = $this->fixture();
        $lead = $this->submit($fixture, 'provider-recreate-old-404');
        $call = $lead->salesCall()->firstOrFail();
        app(SyncB2bSalesCallProvider::class)->handle(IntegrationEvent::query()->sole()->getKey());
        $ready = $call->fresh();

        app(RecreateB2bSalesCallMeeting::class)->handle(
            $fixture['admin'],
            $ready,
            $ready->event_version,
        );
        $recreateEvent = IntegrationEvent::query()->latest('id')->firstOrFail();
        $provider->remoteMissingOnGet = true;

        app(SyncB2bSalesCallProvider::class)->handle($recreateEvent->getKey());

        $afterCleanup = $call->fresh();
        $createEvent = IntegrationEvent::query()->latest('id')->firstOrFail();
        self::assertSame(1, $provider->createCount);
        self::assertSame(0, $provider->cancelCount);
        self::assertSame('processed', $recreateEvent->fresh()->status->value);
        self::assertSame(VideoMeetingOperation::Create, $afterCleanup->provider_operation);
        self::assertSame(VideoMeetingSyncStatus::Pending, $afterCleanup->provider_sync_status);
        self::assertNull($afterCleanup->provider_recreate_meeting_id);
        self::assertNull($afterCleanup->provider_recreate_correlation_key);
        self::assertSame(VideoMeetingOperation::Create->value, $createEvent->payload['operation']);

        app(SyncB2bSalesCallProvider::class)->handle($recreateEvent->getKey());

        self::assertSame(1, $provider->createCount);
        self::assertSame('processed', $recreateEvent->fresh()->status->value);

        app(SyncB2bSalesCallProvider::class)->handle($createEvent->getKey());

        self::assertSame(2, $provider->createCount);
        self::assertSame(VideoMeetingSyncStatus::Ready, $call->fresh()->provider_sync_status);
    }

    public function test_unknown_recreate_create_remains_cancellable_when_switching_to_manual(): void
    {
        $provider = new FakeVideoMeetingProvider;
        $this->app->instance(VideoMeetingProvider::class, $provider);
        $fixture = $this->fixture();
        $lead = $this->submit($fixture, 'provider-recreate-unknown-manual');
        $call = $lead->salesCall()->firstOrFail();
        app(SyncB2bSalesCallProvider::class)->handle(IntegrationEvent::query()->sole()->getKey());
        $ready = $call->fresh();

        app(RecreateB2bSalesCallMeeting::class)->handle(
            $fixture['admin'],
            $ready,
            $ready->event_version,
        );
        $recreateEvent = IntegrationEvent::query()->latest('id')->firstOrFail();
        app(SyncB2bSalesCallProvider::class)->handle($recreateEvent->getKey());
        $createEvent = IntegrationEvent::query()->latest('id')->firstOrFail();
        $provider->throwAfterCreate = true;

        app(SyncB2bSalesCallProvider::class)->handle($createEvent->getKey());

        $unknown = $call->fresh();
        $newCorrelationKey = $unknown->provider_correlation_key;
        self::assertSame(VideoMeetingSyncStatus::ReconciliationRequired, $unknown->provider_sync_status);
        self::assertSame(VideoMeetingOperation::Create, $unknown->provider_operation);
        self::assertNull($unknown->provider_meeting_id);
        self::assertNull($unknown->provider_recreate_meeting_id);
        self::assertNull($unknown->provider_recreate_correlation_key);
        self::assertSame(2, $provider->createCount);
        self::assertSame(1, $provider->cancelCount);

        $manual = app(SetB2bSalesCallMeetingMode::class)->handle(
            $fixture['admin'],
            $unknown,
            VideoMeetingMode::Manual,
            'https://meet.example.test/recreate-unknown',
            $unknown->event_version,
        );
        $cancelEvent = IntegrationEvent::query()->latest('id')->firstOrFail();
        self::assertSame(VideoMeetingOperation::Cancel, $manual->provider_operation);
        self::assertNull($manual->provider_recreate_meeting_id);
        self::assertNull($manual->provider_recreate_correlation_key);

        app(SyncB2bSalesCallProvider::class)->handle($cancelEvent->getKey());

        $final = $call->fresh();
        self::assertSame(VideoMeetingMode::Manual, $final->meeting_mode);
        self::assertSame(VideoMeetingSyncStatus::NotRequired, $final->provider_sync_status);
        self::assertNull($final->provider_operation);
        self::assertNull($final->provider_meeting_id);
        self::assertNull($final->provider_recreate_meeting_id);
        self::assertNull($final->provider_recreate_correlation_key);
        self::assertSame($newCorrelationKey, $final->provider_correlation_key);
        self::assertSame(2, $provider->cancelCount);
        self::assertSame('zoom-2', $provider->lastCancelledMeetingId);
    }

    public function test_unknown_recreate_create_remains_cancellable_when_sales_call_is_cancelled(): void
    {
        $provider = new FakeVideoMeetingProvider;
        $this->app->instance(VideoMeetingProvider::class, $provider);
        $fixture = $this->fixture();
        $lead = $this->submit($fixture, 'provider-recreate-unknown-cancel');
        $call = $lead->salesCall()->firstOrFail();
        app(SyncB2bSalesCallProvider::class)->handle(IntegrationEvent::query()->sole()->getKey());
        $ready = $call->fresh();

        app(RecreateB2bSalesCallMeeting::class)->handle(
            $fixture['admin'],
            $ready,
            $ready->event_version,
        );
        $recreateEvent = IntegrationEvent::query()->latest('id')->firstOrFail();
        app(SyncB2bSalesCallProvider::class)->handle($recreateEvent->getKey());
        $createEvent = IntegrationEvent::query()->latest('id')->firstOrFail();
        $provider->throwAfterCreate = true;
        app(SyncB2bSalesCallProvider::class)->handle($createEvent->getKey());

        $unknown = $call->fresh();
        $cancelled = app(CancelB2bSalesCall::class)->handle(
            $fixture['admin'],
            $unknown,
            $unknown->event_version,
        );
        $cancelEvent = IntegrationEvent::query()->latest('id')->firstOrFail();
        self::assertSame(B2bSalesCallStatus::Cancelled, $cancelled->status);
        self::assertSame(VideoMeetingSyncStatus::ReconciliationRequired, $cancelled->provider_sync_status);
        self::assertSame(VideoMeetingOperation::Cancel, $cancelled->provider_operation);

        app(SyncB2bSalesCallProvider::class)->handle($cancelEvent->getKey());

        $final = $call->fresh();
        self::assertSame(B2bSalesCallStatus::Cancelled, $final->status);
        self::assertSame(VideoMeetingSyncStatus::NotRequired, $final->provider_sync_status);
        self::assertNull($final->provider_operation);
        self::assertNull($final->provider_meeting_id);
        self::assertNull($final->provider_recreate_meeting_id);
        self::assertNull($final->provider_recreate_correlation_key);
        self::assertNull($final->provider_correlation_key);
        self::assertSame(2, $provider->cancelCount);
        self::assertSame('zoom-2', $provider->lastCancelledMeetingId);
    }

    public function test_unknown_recreate_create_stays_reconciliation_required_when_manual_cleanup_cannot_resolve_it(): void
    {
        $provider = new FakeVideoMeetingProvider;
        $this->app->instance(VideoMeetingProvider::class, $provider);
        $fixture = $this->fixture();
        $lead = $this->submit($fixture, 'provider-recreate-unknown-unresolved-manual');
        $call = $lead->salesCall()->firstOrFail();
        app(SyncB2bSalesCallProvider::class)->handle(IntegrationEvent::query()->sole()->getKey());
        $ready = $call->fresh();

        app(RecreateB2bSalesCallMeeting::class)->handle(
            $fixture['admin'],
            $ready,
            $ready->event_version,
        );
        $recreateEvent = IntegrationEvent::query()->latest('id')->firstOrFail();
        app(SyncB2bSalesCallProvider::class)->handle($recreateEvent->getKey());
        $createEvent = IntegrationEvent::query()->latest('id')->firstOrFail();
        $provider->throwAfterCreate = true;
        app(SyncB2bSalesCallProvider::class)->handle($createEvent->getKey());
        $unknown = $call->fresh();
        $newCorrelationKey = $unknown->provider_correlation_key;
        $provider->hideMeetingsFromSearch = true;

        $manual = app(SetB2bSalesCallMeetingMode::class)->handle(
            $fixture['admin'],
            $unknown,
            VideoMeetingMode::Manual,
            'https://meet.example.test/recreate-unresolved-manual',
            $unknown->event_version,
        );
        $cancelEvent = IntegrationEvent::query()->latest('id')->firstOrFail();
        app(SyncB2bSalesCallProvider::class)->handle($cancelEvent->getKey());

        $final = $call->fresh();
        self::assertSame(VideoMeetingMode::Manual, $manual->meeting_mode);
        self::assertSame(VideoMeetingSyncStatus::ReconciliationRequired, $final->provider_sync_status);
        self::assertSame(VideoMeetingOperation::Cancel, $final->provider_operation);
        self::assertNotSame(VideoMeetingSyncStatus::NotRequired, $final->provider_sync_status);
        self::assertSame($newCorrelationKey, $final->provider_correlation_key);
        self::assertSame(1, $provider->cancelCount);
        self::assertSame('failed', $cancelEvent->fresh()->status->value);
    }

    public function test_unknown_recreate_create_stays_reconciliation_required_when_cancelled_cleanup_cannot_resolve_it(): void
    {
        $provider = new FakeVideoMeetingProvider;
        $this->app->instance(VideoMeetingProvider::class, $provider);
        $fixture = $this->fixture();
        $lead = $this->submit($fixture, 'provider-recreate-unknown-unresolved-cancel');
        $call = $lead->salesCall()->firstOrFail();
        app(SyncB2bSalesCallProvider::class)->handle(IntegrationEvent::query()->sole()->getKey());
        $ready = $call->fresh();

        app(RecreateB2bSalesCallMeeting::class)->handle(
            $fixture['admin'],
            $ready,
            $ready->event_version,
        );
        $recreateEvent = IntegrationEvent::query()->latest('id')->firstOrFail();
        app(SyncB2bSalesCallProvider::class)->handle($recreateEvent->getKey());
        $createEvent = IntegrationEvent::query()->latest('id')->firstOrFail();
        $provider->throwAfterCreate = true;
        app(SyncB2bSalesCallProvider::class)->handle($createEvent->getKey());
        $unknown = $call->fresh();
        $provider->hideMeetingsFromSearch = true;

        $cancelled = app(CancelB2bSalesCall::class)->handle(
            $fixture['admin'],
            $unknown,
            $unknown->event_version,
        );
        $cancelEvent = IntegrationEvent::query()->latest('id')->firstOrFail();
        app(SyncB2bSalesCallProvider::class)->handle($cancelEvent->getKey());

        $final = $call->fresh();
        self::assertSame(B2bSalesCallStatus::Cancelled, $cancelled->status);
        self::assertSame(B2bSalesCallStatus::Cancelled, $final->status);
        self::assertSame(VideoMeetingSyncStatus::ReconciliationRequired, $final->provider_sync_status);
        self::assertSame(VideoMeetingOperation::Cancel, $final->provider_operation);
        self::assertNotSame(VideoMeetingSyncStatus::NotRequired, $final->provider_sync_status);
        self::assertSame(1, $provider->cancelCount);
        self::assertSame('failed', $cancelEvent->fresh()->status->value);
    }

    public function test_cancellation_after_unknown_zoom_create_reconciles_and_cancels_the_external_meeting(): void
    {
        $provider = new FakeVideoMeetingProvider;
        $provider->throwAfterCreate = true;
        $this->app->instance(VideoMeetingProvider::class, $provider);
        $fixture = $this->fixture();
        $lead = $this->submit($fixture, 'provider-create-then-cancel');
        $call = $lead->salesCall()->firstOrFail();
        $createEvent = IntegrationEvent::query()->sole();

        app(SyncB2bSalesCallProvider::class)->handle($createEvent->getKey());
        $cancelled = app(CancelB2bSalesCall::class)->handle(
            actor: $fixture['admin'],
            salesCall: $call->fresh(),
            expectedEventVersion: $call->fresh()->event_version,
        );
        $cancelEvent = IntegrationEvent::query()->latest('id')->firstOrFail();

        self::assertSame(VideoMeetingSyncStatus::ReconciliationRequired, $cancelled->provider_sync_status);
        self::assertSame(0, UnavailablePeriod::query()->where('b2b_sales_call_id', $call->getKey())->count());

        app(SyncB2bSalesCallProvider::class)->handle($cancelEvent->getKey());

        $final = $call->fresh();
        self::assertSame(VideoMeetingSyncStatus::NotRequired, $final->provider_sync_status);
        self::assertSame(1, $provider->createCount);
        self::assertSame(1, $provider->cancelCount);
        self::assertNull($final->provider_meeting_id);
    }

    public function test_durable_provider_lease_fences_stale_worker_and_allows_only_the_new_generation_to_finalize(): void
    {
        $provider = new FakeVideoMeetingProvider;
        $this->app->instance(VideoMeetingProvider::class, $provider);
        $fixture = $this->fixture();
        $lead = $this->submit($fixture, 'provider-generation-fence');
        $call = $lead->salesCall()->firstOrFail();
        $event = IntegrationEvent::query()->sole();
        $leases = app(B2bProviderLeaseManager::class);
        $oldLease = $leases->claim($event->getKey());

        self::assertInstanceOf(ProviderOperationLease::class, $oldLease);
        $call->fresh()->forceFill([
            'provider_correlation_key' => 'generation-two',
            'provider_sync_version' => 2,
            'event_version' => 2,
            'provider_operation' => 'create',
            'provider_lease_token' => null,
            'provider_lease_expires_at' => null,
            'provider_lease_event_id' => null,
            'provider_lease_processing_token' => null,
        ])->save();
        $newEvent = app(RecordB2bProviderSyncEvent::class)->handle(
            $fixture['organization'],
            $call->fresh(),
            VideoMeetingOperation::Create,
        );
        self::assertFalse($leases->owns($oldLease));

        app(SyncB2bSalesCallProvider::class)->handle($event->getKey());

        self::assertSame('generation-two', $call->fresh()->provider_correlation_key);
        self::assertNull($call->fresh()->provider_meeting_id);
        self::assertSame(0, $provider->createCount);

        app(SyncB2bSalesCallProvider::class)->handle($newEvent->getKey());

        self::assertSame(1, $provider->createCount);
        self::assertSame(VideoMeetingSyncStatus::Ready, $call->fresh()->provider_sync_status);
    }

    public function test_provider_lease_uses_claim_time_for_a_delayed_worker_deadline(): void
    {
        config()->set('b2b.provider.operation_deadline_seconds', 10);
        config()->set('b2b.provider.lease_margin_seconds', 5);
        config()->set('b2b.provider.request_safety_seconds', 1);
        $provider = new FakeVideoMeetingProvider;
        $this->app->instance(VideoMeetingProvider::class, $provider);
        $fixture = $this->fixture();
        $base = CarbonImmutable::create(2026, 8, 27, 10, 0, 0, 'UTC');
        CarbonImmutable::setTestNow($base);
        $lead = $this->submit($fixture, 'provider-claim-time-deadline');
        $call = $lead->salesCall()->firstOrFail();
        $event = IntegrationEvent::query()->sole();
        $claimedLeaseExpiresAt = null;
        $workerDelayed = false;

        B2bSalesCall::saved(function (B2bSalesCall $saved) use (&$claimedLeaseExpiresAt, &$workerDelayed, $base): void {
            if ($workerDelayed || $saved->provider_lease_token === null) {
                return;
            }

            $claimedLeaseExpiresAt = CarbonImmutable::parse((string) $saved->provider_lease_expires_at)->utc();
            $workerDelayed = true;
            CarbonImmutable::setTestNow($base->addSeconds(6));
        });

        app(SyncB2bSalesCallProvider::class)->handle($event->getKey());

        self::assertTrue($workerDelayed);
        self::assertNotNull($claimedLeaseExpiresAt);
        self::assertTrue($claimedLeaseExpiresAt->equalTo($base->addSeconds(15)));
        $deadline = $provider->lastDeadline;
        self::assertInstanceOf(ProviderOperationDeadline::class, $deadline);
        self::assertTrue($deadline->expiresAt->equalTo($base->addSeconds(10)));
        self::assertEqualsWithDelta(4.0, $deadline->remainingSeconds(), 0.001);
        self::assertSame(1, $provider->createCount);
    }

    public function test_provider_deadline_expires_before_the_lease_and_blocks_remote_mutation(): void
    {
        config()->set('b2b.provider.operation_deadline_seconds', 10);
        config()->set('b2b.provider.lease_margin_seconds', 5);
        config()->set('b2b.provider.request_safety_seconds', 1);
        $provider = new FakeVideoMeetingProvider;
        $fixture = $this->fixture();
        $base = CarbonImmutable::create(2026, 8, 27, 10, 0, 0, 'UTC');
        CarbonImmutable::setTestNow($base);
        $lead = $this->submit($fixture, 'provider-deadline-before-lease');
        $call = $lead->salesCall()->firstOrFail();
        $event = IntegrationEvent::query()->sole();
        $lease = app(B2bProviderLeaseManager::class)->claim($event->getKey());

        self::assertInstanceOf(ProviderOperationLease::class, $lease);
        $deadline = $lease->providerDeadline();
        CarbonImmutable::setTestNow($base->addSeconds(11));
        self::assertTrue($lease->leaseExpiresAt->greaterThan(CarbonImmutable::now('UTC')));
        self::assertFalse($deadline->canStart());

        try {
            $provider->createMeeting(
                $fixture['organization'],
                new VideoMeetingRequest(
                    externalKey: (string) $call->provider_correlation_key,
                    startsAt: $call->startsAtUtc(),
                    durationMinutes: 60,
                    timezone: (string) $call->schedule_timezone,
                    topic: 'Chuklov B2B sales call',
                ),
                $deadline,
            );
            self::fail('A provider mutation started after the claim-time deadline expired.');
        } catch (VideoMeetingException $exception) {
            self::assertSame('zoom_deadline_exhausted', $exception->safeCode);
        }

        self::assertSame(0, $provider->createCount);

        $beforeWriter = $call->fresh();
        CarbonImmutable::setTestNow($base->addSeconds(16));

        try {
            app(RecreateB2bSalesCallMeeting::class)->handle(
                actor: $fixture['admin'],
                salesCall: $beforeWriter,
                expectedEventVersion: $beforeWriter->event_version,
            );
            self::fail('An expired lease allowed a generation-changing writer to discard the current generation.');
        } catch (ValidationException $exception) {
            self::assertSame(B2bProviderMutationGuard::LOST_MESSAGE, $exception->errors()['provider'][0]);
        }

        $afterWriter = $call->fresh();
        self::assertSame($beforeWriter->provider_correlation_key, $afterWriter->provider_correlation_key);
        self::assertSame($beforeWriter->provider_sync_version, $afterWriter->provider_sync_version);
        self::assertSame(VideoMeetingSyncStatus::ReconciliationRequired, $afterWriter->provider_sync_status);
        self::assertNull($afterWriter->provider_lease_token);
        self::assertFalse($deadline->canStart());
        self::assertSame(0, $provider->createCount);
    }

    public function test_worker_pause_after_authority_check_cannot_start_after_claim_deadline(): void
    {
        config()->set('b2b.provider.operation_deadline_seconds', 10);
        config()->set('b2b.provider.lease_margin_seconds', 5);
        config()->set('b2b.provider.request_safety_seconds', 1);
        $provider = new FakeVideoMeetingProvider;
        $this->app->instance(VideoMeetingProvider::class, $provider);
        $fixture = $this->fixture();
        $base = CarbonImmutable::create(2026, 8, 27, 10, 0, 0, 'UTC');
        CarbonImmutable::setTestNow($base);
        $lead = $this->submit($fixture, 'provider-deadline-authority-barrier');
        $call = $lead->salesCall()->firstOrFail();
        $event = IntegrationEvent::query()->sole();
        $provider->beforeCreate = function () use ($base): void {
            CarbonImmutable::setTestNow($base->addSeconds(11));
        };

        app(SyncB2bSalesCallProvider::class)->handle($event->getKey());

        self::assertSame(0, $provider->createCount);
        self::assertSame(VideoMeetingSyncStatus::Failed, $call->fresh()->provider_sync_status);
        self::assertSame('zoom_deadline_exhausted', $call->fresh()->provider_error_code);
        self::assertSame('retryable', $event->fresh()->status->value);
        self::assertNull($call->fresh()->provider_lease_token);
    }

    public function test_active_provider_lease_blocks_every_local_generation_writer_until_worker_finalizes(): void
    {
        $provider = new FakeVideoMeetingProvider;
        $this->app->instance(VideoMeetingProvider::class, $provider);
        $fixture = $this->fixture();
        $lead = $this->submit($fixture, 'provider-active-lease-writers');
        $call = $lead->salesCall()->firstOrFail();
        $correlationKey = $call->provider_correlation_key;
        $event = IntegrationEvent::query()->sole();
        $blocked = [];
        $provider->beforeCreate = function () use ($fixture, $call, &$blocked): void {
            $attempts = [
                'cancel' => fn (): B2bSalesCall => app(CancelB2bSalesCall::class)->handle(
                    actor: $fixture['admin'],
                    salesCall: $call->fresh(),
                    expectedEventVersion: $call->event_version,
                ),
                'reschedule' => fn (): B2bSalesCall => app(RescheduleB2bSalesCall::class)->handle(
                    actor: $fixture['admin'],
                    salesCall: $call->fresh(),
                    newStartsAt: $this->slot(17),
                    requestedTimezone: 'UTC',
                    expectedEventVersion: $call->event_version,
                ),
                'mode' => fn (): B2bSalesCall => app(SetB2bSalesCallMeetingMode::class)->handle(
                    actor: $fixture['admin'],
                    salesCall: $call->fresh(),
                    mode: VideoMeetingMode::Manual,
                    manualMeetingUrl: 'https://meet.example.test/active-lease',
                    expectedEventVersion: $call->event_version,
                ),
                'retry' => fn (): B2bSalesCall => app(RetryB2bSalesCallProvider::class)->handle(
                    actor: $fixture['admin'],
                    salesCall: $call->fresh(),
                    expectedEventVersion: $call->event_version,
                ),
                'recreate' => fn (): B2bSalesCall => app(RecreateB2bSalesCallMeeting::class)->handle(
                    actor: $fixture['admin'],
                    salesCall: $call->fresh(),
                    expectedEventVersion: $call->event_version,
                ),
            ];

            foreach ($attempts as $name => $attempt) {
                try {
                    $attempt();
                } catch (ValidationException $exception) {
                    $blocked[$name] = $exception->errors()['provider'][0] ?? null;
                }
            }
        };

        app(SyncB2bSalesCallProvider::class)->handle($event->getKey());

        self::assertSame([
            'cancel' => B2bProviderMutationGuard::BLOCKED_MESSAGE,
            'reschedule' => B2bProviderMutationGuard::BLOCKED_MESSAGE,
            'mode' => B2bProviderMutationGuard::BLOCKED_MESSAGE,
            'retry' => B2bProviderMutationGuard::BLOCKED_MESSAGE,
            'recreate' => B2bProviderMutationGuard::BLOCKED_MESSAGE,
        ], $blocked);
        self::assertSame(1, $provider->createCount);
        self::assertSame($correlationKey, $call->fresh()->provider_correlation_key);
        self::assertSame(1, $call->fresh()->provider_sync_version);
        self::assertSame(VideoMeetingSyncStatus::Ready, $call->fresh()->provider_sync_status);
    }

    public function test_active_provider_lease_blocks_host_reconciliation_without_rotating_generation(): void
    {
        $provider = new FakeVideoMeetingProvider;
        $this->app->instance(VideoMeetingProvider::class, $provider);
        $fixture = $this->fixture();
        $lead = $this->submit($fixture, 'provider-active-host-reconciliation');
        $call = $lead->salesCall()->firstOrFail();
        app(SyncB2bSalesCallProvider::class)->handle(IntegrationEvent::query()->sole()->getKey());
        $ready = $call->fresh();
        $identity = $ready->providerIdentity();
        self::assertInstanceOf(VideoMeetingIdentity::class, $identity);
        $reconciliation = app(MarkB2bSalesCallProviderReconciliationRequired::class)->handle(
            actor: $fixture['admin'],
            salesCall: $ready,
            identity: $identity,
            errorCode: 'zoom_host_url_404',
            expectedEventVersion: $ready->event_version,
            expectedProviderSyncVersion: $ready->provider_sync_version,
        );
        $event = IntegrationEvent::query()->latest('id')->firstOrFail();
        $lease = app(B2bProviderLeaseManager::class)->claim($event->getKey());
        self::assertInstanceOf(ProviderOperationLease::class, $lease);
        $before = $call->fresh();
        $eventCount = IntegrationEvent::query()->count();

        try {
            app(MarkB2bSalesCallProviderReconciliationRequired::class)->handle(
                actor: $fixture['admin'],
                salesCall: $before,
                identity: $identity,
                errorCode: 'zoom_host_url_404_again',
                expectedEventVersion: $before->event_version,
                expectedProviderSyncVersion: $before->provider_sync_version,
            );
            self::fail('An active provider lease allowed a host reconciliation generation change.');
        } catch (ValidationException $exception) {
            self::assertSame(B2bProviderMutationGuard::BLOCKED_MESSAGE, $exception->errors()['provider'][0]);
        }

        $after = $call->fresh();
        self::assertSame($before->provider_correlation_key, $after->provider_correlation_key);
        self::assertSame($before->provider_sync_version, $after->provider_sync_version);
        self::assertSame($before->event_version, $after->event_version);
        self::assertSame($before->provider_lease_token, $after->provider_lease_token);
        self::assertSame($eventCount, IntegrationEvent::query()->count());
        self::assertSame($reconciliation->provider_correlation_key, $after->provider_correlation_key);
    }

    public function test_expired_provider_lease_is_marked_lost_and_cannot_start_a_blind_recreate(): void
    {
        $provider = new FakeVideoMeetingProvider;
        $this->app->instance(VideoMeetingProvider::class, $provider);
        $fixture = $this->fixture();
        $lead = $this->submit($fixture, 'provider-expired-local-writer');
        $call = $lead->salesCall()->firstOrFail();
        $event = IntegrationEvent::query()->sole();
        $lease = app(B2bProviderLeaseManager::class)->claim($event->getKey());
        self::assertInstanceOf(ProviderOperationLease::class, $lease);
        $before = $call->fresh();

        $call->fresh()->forceFill(['provider_lease_expires_at' => now()->subSecond()])->save();

        try {
            app(RecreateB2bSalesCallMeeting::class)->handle(
                actor: $fixture['admin'],
                salesCall: $call->fresh(),
                expectedEventVersion: $before->event_version,
            );
            self::fail('An expired provider lease allowed a blind recreate.');
        } catch (ValidationException $exception) {
            self::assertSame(B2bProviderMutationGuard::LOST_MESSAGE, $exception->errors()['provider'][0]);
        }

        $final = $call->fresh();
        self::assertSame(VideoMeetingSyncStatus::ReconciliationRequired, $final->provider_sync_status);
        self::assertSame('provider_worker_lost', $final->provider_error_code);
        self::assertSame($before->provider_correlation_key, $final->provider_correlation_key);
        self::assertSame($before->provider_sync_version, $final->provider_sync_version);
        self::assertSame(0, $provider->createCount);
        self::assertNull($final->provider_lease_token);
    }

    public function test_two_provider_workers_cannot_both_create_for_one_event(): void
    {
        $provider = new FakeVideoMeetingProvider;
        $this->app->instance(VideoMeetingProvider::class, $provider);
        $fixture = $this->fixture();
        $lead = $this->submit($fixture, 'provider-single-create');
        $event = IntegrationEvent::query()->sole();

        app(SyncB2bSalesCallProvider::class)->handle($event->getKey());
        app(SyncB2bSalesCallProvider::class)->handle($event->getKey());

        self::assertSame(1, $provider->createCount);
        self::assertSame('processed', $event->fresh()->status->value);
        self::assertSame(VideoMeetingSyncStatus::Ready, $lead->salesCall()->firstOrFail()->fresh()->provider_sync_status);
    }

    public function test_expired_provider_lease_cannot_finalize_and_next_worker_reconciles_without_creating_again(): void
    {
        $provider = new FakeVideoMeetingProvider;
        $this->app->instance(VideoMeetingProvider::class, $provider);
        $fixture = $this->fixture();
        $lead = $this->submit($fixture, 'provider-expired-lease');
        $call = $lead->salesCall()->firstOrFail();
        $event = IntegrationEvent::query()->sole();
        $provider->afterCreate = function () use ($call): void {
            B2bSalesCall::query()
                ->whereKey($call->getKey())
                ->update(['provider_lease_expires_at' => now()->subSecond()]);
        };

        app(SyncB2bSalesCallProvider::class)->handle($event->getKey());

        self::assertSame(1, $provider->createCount);
        self::assertSame('processing', $event->fresh()->status->value);
        self::assertNull($call->fresh()->provider_meeting_id);

        app(SyncB2bSalesCallProvider::class)->handle($event->getKey());

        self::assertSame(1, $provider->createCount);
        self::assertSame(VideoMeetingSyncStatus::Ready, $call->fresh()->provider_sync_status);
        self::assertSame('processed', $event->fresh()->status->value);
    }

    public function test_ready_zoom_call_notifies_through_scenario_and_host_url_is_never_stored_for_clients(): void
    {
        $provider = new FakeVideoMeetingProvider;
        $this->app->instance(VideoMeetingProvider::class, $provider);
        $fixture = $this->fixture();
        $lead = $this->submit($fixture, 'provider-ready');
        $event = IntegrationEvent::query()->sole();

        app(SyncB2bSalesCallProvider::class)->handle($event->getKey());
        $call = $lead->salesCall()->firstOrFail()->fresh();
        $hostUrl = app(GetB2bSalesCallHostLaunchUrl::class)->handle($fixture['admin'], $call);

        self::assertSame('https://us02web.zoom.us/start/zoom-1', $hostUrl);
        self::assertSame(VideoMeetingSyncStatus::Ready, $call->provider_sync_status);
        self::assertNull($call->getAttribute('provider_host_start_url'));
        self::assertSame(1, DB::table('scenario_events')->where('event_name', 'b2b.sales_call.ready')->count());
        self::assertStringNotContainsString('host', (string) $call->provider_join_url);
    }

    public function test_host_launch_requires_a_current_ready_zoom_generation(): void
    {
        $provider = new FakeVideoMeetingProvider;
        $this->app->instance(VideoMeetingProvider::class, $provider);
        $fixture = $this->fixture();
        $lead = $this->submit($fixture, 'host-current-state');
        $call = $lead->salesCall()->firstOrFail();
        app(SyncB2bSalesCallProvider::class)->handle(IntegrationEvent::query()->sole()->getKey());

        $states = [
            VideoMeetingSyncStatus::ReconciliationRequired,
            VideoMeetingSyncStatus::Pending,
            VideoMeetingSyncStatus::Failed,
            VideoMeetingSyncStatus::CancellationPending,
        ];
        $operations = [
            VideoMeetingOperation::Reconcile,
            VideoMeetingOperation::Update,
            null,
            VideoMeetingOperation::Cancel,
        ];

        foreach ($states as $index => $state) {
            $call->fresh()->forceFill([
                'provider_sync_status' => $state,
                'provider_operation' => $operations[$index],
            ])->save();

            try {
                app(GetB2bSalesCallHostLaunchUrl::class)->handle($fixture['admin'], $call->fresh());
                self::fail('A non-ready provider state was allowed to launch a host meeting.');
            } catch (ValidationException $exception) {
                self::assertSame(
                    'The Zoom host link is unavailable because the meeting is not in a current ready state. Refresh and retry from the CRM.',
                    $exception->errors()['provider'][0],
                );
            }
        }

        self::assertSame(0, $provider->hostLaunchCount);
    }

    public function test_host_url_is_discarded_when_cancel_or_reschedule_changes_state_during_provider_read(): void
    {
        $provider = new FakeVideoMeetingProvider;
        $this->app->instance(VideoMeetingProvider::class, $provider);

        foreach (['cancel', 'reschedule', 'recreate'] as $transition) {
            $fixture = $this->fixture();
            $lead = $this->submit($fixture, 'host-race-'.$transition);
            $call = $lead->salesCall()->firstOrFail();
            app(SyncB2bSalesCallProvider::class)->handle(
                IntegrationEvent::query()->where('organization_id', $fixture['organization']->getKey())->sole()->getKey(),
            );
            $ready = $call->fresh();
            $provider->beforeHostLaunch = function () use ($fixture, $ready, $transition): void {
                if ($transition === 'cancel') {
                    app(CancelB2bSalesCall::class)->handle(
                        actor: $fixture['admin'],
                        salesCall: $ready->fresh(),
                        expectedEventVersion: $ready->event_version,
                    );

                    return;
                }

                if ($transition === 'recreate') {
                    app(RecreateB2bSalesCallMeeting::class)->handle(
                        actor: $fixture['admin'],
                        salesCall: $ready->fresh(),
                        expectedEventVersion: $ready->event_version,
                    );

                    return;
                }

                app(RescheduleB2bSalesCall::class)->handle(
                    actor: $fixture['admin'],
                    salesCall: $ready->fresh(),
                    newStartsAt: $this->slot(17),
                    requestedTimezone: 'UTC',
                    expectedEventVersion: $ready->event_version,
                );
            };

            try {
                app(GetB2bSalesCallHostLaunchUrl::class)->handle($fixture['admin'], $ready);
                self::fail('A host URL fetched before a state transition was returned to the caller.');
            } catch (ValidationException $exception) {
                self::assertSame(
                    'The Zoom host link became stale before launch. Refresh and retry from the CRM.',
                    $exception->errors()['provider'][0],
                );
            }
        }

        self::assertSame(3, $provider->hostLaunchCount);
    }

    public function test_host_launch_endpoint_rejects_an_untrusted_provider_url(): void
    {
        $provider = new FakeVideoMeetingProvider;
        $provider->hostLaunchUrl = 'https://attacker.example/start/zoom-1';
        $this->app->instance(VideoMeetingProvider::class, $provider);
        $fixture = $this->fixture();
        $lead = $this->submit($fixture, 'provider-host-allowlist');
        $call = $lead->salesCall()->firstOrFail();
        app(SyncB2bSalesCallProvider::class)->handle(IntegrationEvent::query()->sole()->getKey());

        try {
            app(GetB2bSalesCallHostLaunchUrl::class)->handle($fixture['admin'], $call->fresh());
            self::fail('The host launch endpoint accepted an untrusted provider URL.');
        } catch (ValidationException $exception) {
            self::assertSame('The Zoom host link is invalid. Retry from the CRM.', $exception->errors()['provider'][0]);
        }
    }

    public function test_stale_host_provider_identity_enters_reconciliation_without_recreating_automatically(): void
    {
        $provider = new FakeVideoMeetingProvider;
        $this->app->instance(VideoMeetingProvider::class, $provider);
        $fixture = $this->fixture();
        $lead = $this->submit($fixture, 'provider-stale-host');
        $call = $lead->salesCall()->firstOrFail();
        app(SyncB2bSalesCallProvider::class)->handle(IntegrationEvent::query()->sole()->getKey());
        $ready = $call->fresh();
        $provider->throwOnHostLaunch = true;

        try {
            app(GetB2bSalesCallHostLaunchUrl::class)->handle($fixture['admin'], $ready);
            self::fail('A stale Zoom host identity was treated as a valid launch target.');
        } catch (ValidationException $exception) {
            self::assertSame(
                'The Zoom meeting is no longer available. Reconcile or recreate it before launching.',
                $exception->errors()['provider'][0],
            );
        }

        $reconciled = $call->fresh();
        self::assertSame(VideoMeetingSyncStatus::ReconciliationRequired, $reconciled->provider_sync_status);
        self::assertSame(VideoMeetingOperation::Reconcile, $reconciled->provider_operation);
        self::assertNull($reconciled->provider_join_url);
        self::assertSame(1, $provider->createCount);
        self::assertSame(2, IntegrationEvent::query()->count());
    }

    public function test_host_launch_rejects_a_cross_organization_sales_call(): void
    {
        $first = $this->fixture();
        $second = $this->fixture();
        $secondCall = $this->submit($second, 'host-cross-org')->salesCall()->firstOrFail();
        $this->setOrganization($first['organization']);

        try {
            app(GetB2bSalesCallHostLaunchUrl::class)->handle(
                $first['admin'],
                $secondCall,
            );
            self::fail('A host launch crossed the organization boundary.');
        } catch (AuthorizationException) {
            self::assertTrue(true);
        }
    }

    public function test_operational_state_transitions_fail_closed_and_crm_requires_b2b_permission(): void
    {
        $fixture = $this->fixture();
        $lead = $this->submit($fixture, 'state-transition');
        $updated = app(UpdateB2bLeadStatus::class)->handle(
            actor: $fixture['admin'],
            lead: $lead,
            status: B2bLeadStatus::Contacted,
            expectedEventVersion: $lead->event_version,
        );

        self::assertSame(B2bLeadStatus::Contacted, $updated->status);

        try {
            app(UpdateB2bLeadStatus::class)->handle(
                actor: $fixture['admin'],
                lead: $updated,
                status: B2bLeadStatus::New,
                expectedEventVersion: $updated->event_version,
            );
            self::fail('An invalid B2B lead transition was accepted.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('status', $exception->errors());
        }

        $staff = User::factory()->forOrganization($fixture['organization'], OrganizationRole::Staff)->create();
        self::assertTrue($staff->hasPermission(OrganizationPermission::ViewB2bLeads, $fixture['organization']));
        self::assertTrue($staff->hasPermission(OrganizationPermission::ManageB2bLeads, $fixture['organization']));
    }

    public function test_telegram_source_retry_uses_the_same_authoritative_lead(): void
    {
        $fixture = $this->fixture();
        $first = $this->submit($fixture, 'telegram-retry', source: B2bLeadSource::Telegram);
        $second = $this->submit($fixture, 'telegram-retry', source: B2bLeadSource::Telegram);

        self::assertSame($first->getKey(), $second->getKey());
        self::assertSame(B2bLeadSource::Telegram, $first->source_channel);
        self::assertSame(1, B2bLead::query()->count());
        self::assertSame(1, B2bSalesCall::query()->count());
    }

    public function test_b2b_data_does_not_copy_medical_fields_into_the_lead_or_audit_payload(): void
    {
        $fixture = $this->fixture();
        $lead = $this->submit($fixture, 'privacy-boundary');
        $auditPayload = AuditEvent::query()
            ->where('organization_id', $fixture['organization']->getKey())
            ->whereIn('action', ['b2b.lead.submitted', 'b2b.sales_call.created'])
            ->pluck('metadata')
            ->map(static fn (array $metadata): string => json_encode($metadata, JSON_THROW_ON_ERROR))
            ->implode('|');
        $columns = DB::getSchemaBuilder()->getColumnListing('b2b_leads');

        self::assertNotContains('medical_profile', $columns);
        self::assertNotContains('health_data', $columns);
        self::assertStringNotContainsString('medical', strtolower($auditPayload));
        self::assertStringNotContainsString('health', strtolower($auditPayload));
        self::assertSame($fixture['client']->getKey(), $lead->client_id);
    }

    /**
     * @return array{organization: Organization, admin: User, client: Client, specialist: Specialist, service: Service}
     */
    private function fixture(
        bool $withAnswer = true,
        string $specialistTimezone = 'UTC',
        string $clientTimezone = 'UTC',
    ): array {
        $organization = Organization::factory()->create(['timezone' => 'UTC']);
        $admin = User::factory()->forOrganization($organization)->create();
        $client = Client::factory()->forOrganization($organization)->create(['timezone' => $clientTimezone]);
        $specialist = Specialist::factory()->forOrganization($organization)->create(['timezone' => $specialistTimezone]);
        $service = Service::factory()->forOrganization($organization)->create([
            'duration_minutes' => 60,
            'buffer_minutes' => 0,
            'formats' => ['office', 'online'],
        ]);
        $this->setOrganization($organization);
        foreach ([OrganizationFeature::ClientRecords, OrganizationFeature::ServiceCatalog] as $feature) {
            OrganizationFeatureFlag::factory()->forOrganization($organization)->create([
                'feature_key' => $feature->value,
                'enabled' => true,
            ]);
        }
        app(SetOrganizationSetting::class)->handle(
            $admin,
            OrganizationSettingKey::B2bSalesCallDurationMinutes,
            60,
        );
        app(SetOrganizationSetting::class)->handle(
            $admin,
            OrganizationSettingKey::B2bZoomHostLicensed,
            true,
        );
        app(AssignSpecialistToService::class)->handle($admin, $specialist, $service);
        app(SetSpecialistWorkingHours::class)->handle($admin, $specialist, [[
            'weekday' => 1,
            'start_time' => '09:00',
            'end_time' => '19:00',
        ]]);
        if ($withAnswer) {
            app(SetClientB2bSpecialistAnswer::class)->handle($client, $client, B2bSpecialistAnswer::Yes, 'portal');
        }

        return compact('organization', 'admin', 'client', 'specialist', 'service');
    }

    /** @param array{organization: Organization, admin: User, client: Client, specialist: Specialist, service: Service} $fixture */
    private function submit(
        array $fixture,
        string $key,
        int $hour = 15,
        ?CarbonImmutable $startsAt = null,
        ?string $requestedTimezone = null,
        B2bLeadSource $source = B2bLeadSource::Portal,
        VideoMeetingMode $meetingMode = VideoMeetingMode::Automatic,
        ?string $manualMeetingUrl = null,
    ): B2bLead {
        return app(SubmitB2bLead::class)->handle(
            actor: $fixture['client'],
            client: $fixture['client'],
            specialist: $fixture['specialist'],
            startsAt: $startsAt ?? $this->slot($hour, (string) $fixture['client']->timezone),
            requestedTimezone: $requestedTimezone ?? (string) $fixture['client']->timezone,
            idempotencyKey: $key,
            source: $source,
            meetingMode: $meetingMode,
            manualMeetingUrl: $manualMeetingUrl,
        );
    }

    private function slot(int $hour = 15, string $timezone = 'UTC'): CarbonImmutable
    {
        return CarbonImmutable::create(2026, 8, 31, $hour, 0, 0, $timezone);
    }

    private function salesCallModeState(B2bSalesCall $call): array
    {
        return (array) $call->getRawOriginal();
    }

    private function remoteMeeting(VideoMeetingIdentity $identity, string $correlationKey): VideoMeetingResult
    {
        return $this->remoteMeetingWithSchedule(
            identity: $identity,
            correlationKey: $correlationKey,
            startsAt: $this->slot(),
            durationMinutes: 60,
            timezone: 'UTC',
        );
    }

    private function remoteMeetingWithSchedule(
        VideoMeetingIdentity $identity,
        string $correlationKey,
        CarbonImmutable $startsAt,
        int $durationMinutes,
        string $timezone,
    ): VideoMeetingResult {
        return new VideoMeetingResult(
            identity: $identity,
            joinUrl: 'https://zoom.example.test/join/'.$identity->meetingId,
            synchronizedAt: CarbonImmutable::now('UTC'),
            startsAt: $startsAt->utc(),
            durationMinutes: $durationMinutes,
            timezone: $timezone,
            agenda: 'CHUKLOV-B2B:'.$correlationKey,
        );
    }

    /** @param array{organization: Organization, admin: User, client: Client, specialist: Specialist, service: Service} $fixture */
    private function client(array $fixture): Client
    {
        $client = Client::factory()->forOrganization($fixture['organization'])->create([
            'timezone' => $fixture['client']->timezone,
        ]);
        app(SetClientB2bSpecialistAnswer::class)->handle(
            actor: $fixture['admin'],
            client: $client,
            answer: B2bSpecialistAnswer::Yes,
            source: 'crm',
        );

        return $client;
    }

    private function setOrganization(Organization $organization): void
    {
        config()->set('tenancy.default_organization_id', $organization->getKey());
        app(OrganizationContext::class)->set($organization);
    }

    private function credential(
        Organization $organization,
        string $provider,
        string $name,
        CredentialStatus $status,
    ): OrganizationCredential {
        $credential = OrganizationCredential::factory()->forOrganization($organization)->make();
        $credential->forceFill([
            'provider' => $provider,
            'credential_name' => $name,
            'status' => $status,
            'credentials' => $provider === 'zoom' && $name === (string) config('b2b.credential_name')
                ? [
                    'account_id' => 'account-'.$organization->getKey(),
                    'client_id' => 'client-'.$organization->getKey(),
                    'client_secret' => 'secret-'.$organization->getKey(),
                    'host_user_id' => 'host-'.$organization->getKey(),
                ]
                : ['token' => 'test-secret'],
        ])->save();

        return $credential;
    }

    private function withMalformedPersistedInterval(
        B2bSalesCall $call,
        string $endsAt,
        Closure $callback,
    ): void {
        $original = DB::table('b2b_sales_calls')
            ->where('id', $call->getKey())
            ->first(['starts_at', 'ends_at']);
        self::assertNotNull($original);

        $constraintDropped = false;
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE b2b_sales_calls DROP CONSTRAINT IF EXISTS b2b_sales_calls_exact_interval_ck');
            $constraintDropped = true;
        }

        try {
            DB::table('b2b_sales_calls')->where('id', $call->getKey())->update([
                'ends_at' => $endsAt,
            ]);
            $callback();
        } finally {
            DB::table('b2b_sales_calls')->where('id', $call->getKey())->update([
                'starts_at' => $original->starts_at,
                'ends_at' => $original->ends_at,
            ]);
            if ($constraintDropped) {
                DB::statement(
                    "ALTER TABLE b2b_sales_calls ADD CONSTRAINT b2b_sales_calls_exact_interval_ck CHECK (ends_at > starts_at AND starts_at = date_trunc('minute', starts_at) AND ends_at = date_trunc('minute', ends_at))",
                );
            }
        }
    }

    private function setPortalClient(Client $client): void
    {
        app(ClientPortalContext::class)->set($client);
    }
}

final class FakeVideoMeetingProvider implements VideoMeetingProvider
{
    public ?Closure $beforeCreate = null;

    public ?Closure $afterCreate = null;

    public ?Closure $beforeHostLaunch = null;

    public ?VideoMeetingRequest $lastRequest = null;

    public ?ProviderOperationDeadline $lastDeadline = null;

    public int $createCount = 0;

    public int $updateCount = 0;

    public int $cancelCount = 0;

    public ?string $lastCancelledMeetingId = null;

    public int $hostLaunchCount = 0;

    public bool $throwAfterCreate = false;

    public bool $failPermanently = false;

    public bool $hideMeetingsFromSearch = false;

    public bool $throwAfterCancel = false;

    public bool $leaveMeetingAfterCancelFailure = false;

    public bool $throwAfterUpdate = false;

    public bool $throwOnUpdate = false;

    public bool $throwOnHostLaunch = false;

    public ?CarbonImmutable $createdStartsAtOverride = null;

    public ?int $createdDurationOverride = null;

    public ?string $createdTimezoneOverride = null;

    public bool $leaveScheduleMismatchedAfterUpdate = false;

    public ?string $hostLaunchUrl = null;

    public bool $remoteMissingOnGet = false;

    public ?VideoMeetingResult $remoteMeetingOverride = null;

    public ?VideoMeetingResult $searchMeetingOverride = null;

    /** @var array<string, VideoMeetingResult> */
    private array $meetings = [];

    /** @var list<string> */
    private array $cancelled = [];

    public function name(): string
    {
        return 'zoom';
    }

    public function createMeeting(
        Organization $organization,
        VideoMeetingRequest $request,
        ProviderOperationDeadline $deadline,
    ): VideoMeetingResult {
        $this->lastDeadline = $deadline;
        $this->lastRequest = $request;

        if ($this->beforeCreate instanceof Closure) {
            ($this->beforeCreate)();
            $this->beforeCreate = null;
        }

        $this->ensureDeadline($deadline);

        if ($this->failPermanently) {
            throw VideoMeetingException::permanent('zoom_rejected');
        }

        $this->createCount++;
        $identity = new VideoMeetingIdentity(
            meetingId: 'zoom-'.$this->createCount,
            meetingUuid: 'uuid-'.$this->createCount,
            providerAccountAffinity: new ProviderAccountAffinity(
                accountId: 'test-account',
                hostUserId: 'test-host',
            ),
        );
        $result = new VideoMeetingResult(
            identity: $identity,
            joinUrl: 'https://zoom.example.test/join/'.$identity->meetingId,
            synchronizedAt: CarbonImmutable::now('UTC'),
            startsAt: ($this->createdStartsAtOverride ?? $request->startsAt)->utc(),
            durationMinutes: $this->createdDurationOverride ?? $request->durationMinutes,
            timezone: $this->createdTimezoneOverride ?? $request->timezone,
            agenda: 'CHUKLOV-B2B:'.$request->externalKey,
        );
        $this->meetings[$request->externalKey] = $result;
        if ($this->afterCreate instanceof Closure) {
            ($this->afterCreate)();
            $this->afterCreate = null;
        }

        if ($this->throwAfterCreate) {
            $this->throwAfterCreate = false;
            throw VideoMeetingException::retryable('zoom_response_lost', true);
        }

        return $result;
    }

    public function updateMeeting(
        Organization $organization,
        VideoMeetingIdentity $identity,
        VideoMeetingRequest $request,
        ProviderOperationDeadline $deadline,
    ): void {
        $this->lastDeadline = $deadline;
        $this->ensureDeadline($deadline);
        $this->lastRequest = $request;
        $remote = $this->getMeeting($organization, $identity, $request, $deadline);
        if (! $remote instanceof VideoMeetingResult) {
            throw VideoMeetingException::reconciliationRequired('zoom_update_identity_missing');
        }
        $this->assertExpectedRemote($remote, $identity, $request);
        $this->updateCount++;

        if ($this->throwOnUpdate) {
            $this->throwOnUpdate = false;
            throw VideoMeetingException::reconciliationRequired('zoom_update_404');
        }

        if (! $this->leaveScheduleMismatchedAfterUpdate) {
            $updated = new VideoMeetingResult(
                identity: $remote->identity,
                joinUrl: $remote->joinUrl,
                synchronizedAt: CarbonImmutable::now('UTC'),
                startsAt: $request->startsAt->utc(),
                durationMinutes: $request->durationMinutes,
                timezone: $request->timezone,
                agenda: $remote->agenda,
            );
            $this->replaceMeeting($updated);
            $this->remoteMeetingOverride = $updated;
        }

        if ($this->throwAfterUpdate) {
            $this->throwAfterUpdate = false;
            throw VideoMeetingException::retryable('zoom_update_response_lost', true);
        }
    }

    public function cancelMeeting(
        Organization $organization,
        VideoMeetingIdentity $identity,
        VideoMeetingRequest $request,
        ProviderOperationDeadline $deadline,
    ): void {
        $this->lastDeadline = $deadline;
        $this->ensureDeadline($deadline);
        $remote = $this->getMeeting($organization, $identity, $request, $deadline);
        if (! $remote instanceof VideoMeetingResult) {
            return;
        }
        $this->assertExpectedRemote($remote, $identity, $request);
        $this->cancelCount++;
        $this->lastCancelledMeetingId = $identity->meetingId;
        if (! $this->throwAfterCancel || ! $this->leaveMeetingAfterCancelFailure) {
            $this->cancelled[] = $identity->meetingId;
        }

        if ($this->throwAfterCancel) {
            $this->throwAfterCancel = false;
            throw VideoMeetingException::retryable('zoom_cancel_response_lost', true);
        }
    }

    public function obtainHostLaunchUrl(
        Organization $organization,
        VideoMeetingIdentity $identity,
        VideoMeetingRequest $request,
        ProviderOperationDeadline $deadline,
    ): string {
        $this->hostLaunchCount++;
        if ($this->beforeHostLaunch instanceof Closure) {
            ($this->beforeHostLaunch)();
            $this->beforeHostLaunch = null;
        }

        if ($this->throwOnHostLaunch) {
            $this->throwOnHostLaunch = false;
            throw VideoMeetingException::permanent('zoom_host_url_404');
        }

        $remote = $this->getMeeting($organization, $identity, $request, $deadline);
        if (! $remote instanceof VideoMeetingResult) {
            throw VideoMeetingException::permanent('zoom_host_url_404');
        }
        $this->assertExpectedRemote($remote, $identity, $request);
        if (! $remote->matchesRequest($request)) {
            throw VideoMeetingException::reconciliationRequired('zoom_schedule_mismatch');
        }

        return $this->hostLaunchUrl ?? 'https://us02web.zoom.us/start/'.$identity->meetingId;
    }

    public function findMeeting(
        Organization $organization,
        VideoMeetingRequest $request,
        ProviderOperationDeadline $deadline,
    ): ?VideoMeetingResult {
        if ($this->hideMeetingsFromSearch) {
            return null;
        }

        if ($this->searchMeetingOverride instanceof VideoMeetingResult
            && $this->searchMeetingOverride->matchesCorrelation($request)) {
            return $this->searchMeetingOverride;
        }

        $result = $this->meetings[$request->externalKey] ?? null;

        if ($result === null || in_array($result->identity->meetingId, $this->cancelled, true)) {
            return null;
        }

        return $result;
    }

    public function getMeeting(
        Organization $organization,
        VideoMeetingIdentity $identity,
        VideoMeetingRequest $request,
        ProviderOperationDeadline $deadline,
    ): ?VideoMeetingResult {
        if ($this->remoteMissingOnGet || in_array($identity->meetingId, $this->cancelled, true)) {
            return null;
        }

        if ($this->remoteMeetingOverride instanceof VideoMeetingResult) {
            return $this->remoteMeetingOverride;
        }

        foreach ($this->meetings as $meeting) {
            if ($meeting->identity->meetingId === $identity->meetingId) {
                return $meeting;
            }
        }

        return null;
    }

    private function replaceMeeting(VideoMeetingResult $result): void
    {
        foreach ($this->meetings as $externalKey => $meeting) {
            if ($meeting->identity->meetingId === $result->identity->meetingId) {
                $this->meetings[$externalKey] = $result;

                return;
            }
        }
    }

    private function assertExpectedRemote(
        VideoMeetingResult $remote,
        VideoMeetingIdentity $identity,
        VideoMeetingRequest $request,
    ): void {
        if (! $remote->matchesIdentity($identity)) {
            throw VideoMeetingException::reconciliationRequired('zoom_meeting_identity_mismatch');
        }
        if (! $remote->matchesCorrelation($request)) {
            throw VideoMeetingException::reconciliationRequired('zoom_meeting_correlation_mismatch');
        }
    }

    private function ensureDeadline(ProviderOperationDeadline $deadline): void
    {
        if (! $deadline->canStart()) {
            throw VideoMeetingException::retryable('zoom_deadline_exhausted');
        }
    }
}
