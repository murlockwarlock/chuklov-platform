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
use App\Modules\B2B\Application\ListB2bLeadsForCrm;
use App\Modules\B2B\Application\ListB2bSalesCallAvailability;
use App\Modules\B2B\Application\RecordB2bProviderSyncEvent;
use App\Modules\B2B\Application\RecreateB2bSalesCallMeeting;
use App\Modules\B2B\Application\RescheduleB2bSalesCall;
use App\Modules\B2B\Application\RetryB2bSalesCallProvider;
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
use App\Modules\B2B\Domain\ValueObjects\ProviderOperationDeadline;
use App\Modules\B2B\Domain\ValueObjects\ProviderOperationLease;
use App\Modules\B2B\Domain\ValueObjects\VideoMeetingIdentity;
use App\Modules\B2B\Domain\ValueObjects\VideoMeetingRequest;
use App\Modules\B2B\Domain\ValueObjects\VideoMeetingResult;
use App\Modules\B2B\Infrastructure\Video\VideoMeetingException;
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
use App\Modules\Security\Domain\Models\AuditEvent;
use App\Modules\Services\Domain\Models\Service;
use App\Modules\Specialists\Domain\Models\Specialist;
use Carbon\CarbonImmutable;
use Closure;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia;
use Livewire\Livewire;
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

        $menu = app(GetTelegramMenu::class)->handle('ru');
        $b2b = collect($menu)->firstWhere('key', 'b2b');

        self::assertSame('🚀 Хочешь себе такого бота? / Развить бизнес', $b2b['label']);
        self::assertSame(rtrim((string) config('app.url'), '/').'/portal/b2b', $b2b['url']);
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
        self::assertSame('2026-08-31T15:45:00+00:00', $call->endsAtUtc()->toIso8601String());

        app(SyncB2bSalesCallProvider::class)->handle(IntegrationEvent::query()->sole()->getKey());

        self::assertSame(45, $provider->lastRequest?->durationMinutes);
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
        self::assertSame(1, DB::table('scenario_events')->where('event_name', 'b2b.sales_call.ready')->count());
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
        self::assertSame(2, $provider->updateCount);
        self::assertSame(VideoMeetingSyncStatus::Ready, $rescheduled->fresh()->provider_sync_status);
        self::assertSame('zoom-1', $rescheduled->fresh()->provider_meeting_id);
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
        app(SyncB2bSalesCallProvider::class)->handle($recreateEvent->getKey());

        self::assertNotSame($oldCorrelationKey, $recreated->fresh()->provider_correlation_key);
        self::assertSame(2, $provider->createCount);
        self::assertSame(1, $provider->cancelCount);
        self::assertSame('zoom-2', $recreated->fresh()->provider_meeting_id);
        self::assertSame(VideoMeetingSyncStatus::Ready, $recreated->fresh()->provider_sync_status);
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

    public int $createCount = 0;

    public int $updateCount = 0;

    public int $cancelCount = 0;

    public int $hostLaunchCount = 0;

    public bool $throwAfterCreate = false;

    public bool $failPermanently = false;

    public bool $hideMeetingsFromSearch = false;

    public bool $throwAfterCancel = false;

    public bool $throwAfterUpdate = false;

    public bool $throwOnUpdate = false;

    public bool $throwOnHostLaunch = false;

    public ?string $hostLaunchUrl = null;

    public bool $remoteMissingOnGet = false;

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
        $this->lastRequest = $request;

        if ($this->beforeCreate instanceof Closure) {
            ($this->beforeCreate)();
            $this->beforeCreate = null;
        }

        if ($this->failPermanently) {
            throw VideoMeetingException::permanent('zoom_rejected');
        }

        $this->createCount++;
        $identity = new VideoMeetingIdentity('zoom-'.$this->createCount, 'uuid-'.$this->createCount);
        $result = new VideoMeetingResult(
            identity: $identity,
            joinUrl: 'https://zoom.example.test/join/'.$identity->meetingId,
            synchronizedAt: CarbonImmutable::now('UTC'),
            startsAt: $request->startsAt->utc(),
            durationMinutes: $request->durationMinutes,
            timezone: $request->timezone,
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
        $this->lastRequest = $request;
        $this->updateCount++;

        if ($this->throwOnUpdate) {
            $this->throwOnUpdate = false;
            throw VideoMeetingException::reconciliationRequired('zoom_update_404');
        }

        if ($this->throwAfterUpdate) {
            $this->throwAfterUpdate = false;
            throw VideoMeetingException::retryable('zoom_update_response_lost', true);
        }
    }

    public function cancelMeeting(
        Organization $organization,
        VideoMeetingIdentity $identity,
        ProviderOperationDeadline $deadline,
    ): void {
        $this->cancelCount++;
        $this->cancelled[] = $identity->meetingId;

        if ($this->throwAfterCancel) {
            $this->throwAfterCancel = false;
            throw VideoMeetingException::retryable('zoom_cancel_response_lost', true);
        }
    }

    public function obtainHostLaunchUrl(
        Organization $organization,
        VideoMeetingIdentity $identity,
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

        $result = $this->meetings[$request->externalKey] ?? null;

        if ($result === null || in_array($result->identity->meetingId, $this->cancelled, true)) {
            return null;
        }

        return $result;
    }

    public function getMeeting(
        Organization $organization,
        VideoMeetingIdentity $identity,
        ProviderOperationDeadline $deadline,
    ): ?VideoMeetingResult {
        if ($this->remoteMissingOnGet || in_array($identity->meetingId, $this->cancelled, true)) {
            return null;
        }

        foreach ($this->meetings as $meeting) {
            if ($meeting->identity->meetingId === $identity->meetingId) {
                return $meeting;
            }
        }

        return null;
    }
}
