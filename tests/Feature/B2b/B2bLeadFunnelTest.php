<?php

namespace Tests\Feature\B2b;

use App\Models\User;
use App\Modules\Analytics\Application\Data\DashboardPeriod;
use App\Modules\Analytics\Application\SchedulingAnalytics;
use App\Modules\B2B\Application\CancelB2bSalesCall;
use App\Modules\B2B\Application\GetB2bSalesCallHostLaunchUrl;
use App\Modules\B2B\Application\ListB2bLeadsForCrm;
use App\Modules\B2B\Application\RescheduleB2bSalesCall;
use App\Modules\B2B\Application\RetryB2bSalesCallProvider;
use App\Modules\B2B\Application\SetB2bSalesCallMeetingMode;
use App\Modules\B2B\Application\SubmitB2bLead;
use App\Modules\B2B\Application\SyncB2bSalesCallProvider;
use App\Modules\B2B\Application\UpdateB2bLeadStatus;
use App\Modules\B2B\Domain\Contracts\VideoMeetingProvider;
use App\Modules\B2B\Domain\Enums\B2bLeadSource;
use App\Modules\B2B\Domain\Enums\B2bLeadStatus;
use App\Modules\B2B\Domain\Enums\VideoMeetingMode;
use App\Modules\B2B\Domain\Enums\VideoMeetingSyncStatus;
use App\Modules\B2B\Domain\Models\B2bLead;
use App\Modules\B2B\Domain\Models\B2bSalesCall;
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
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationFeature;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use App\Modules\Organizations\Domain\Enums\OrganizationRole;
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
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia;
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

        self::assertSame(VideoMeetingSyncStatus::CancellationPending, $cancelled->provider_sync_status);
        self::assertSame(0, UnavailablePeriod::query()->where('b2b_sales_call_id', $call->getKey())->count());

        app(SyncB2bSalesCallProvider::class)->handle($cancelEvent->getKey());

        $final = $call->fresh();
        self::assertSame(VideoMeetingSyncStatus::NotRequired, $final->provider_sync_status);
        self::assertSame(1, $provider->createCount);
        self::assertSame(1, $provider->cancelCount);
        self::assertNull($final->provider_meeting_id);
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

        self::assertSame('https://zoom.example.test/host/zoom-1', $hostUrl);
        self::assertSame(VideoMeetingSyncStatus::Ready, $call->provider_sync_status);
        self::assertNull($call->getAttribute('provider_host_start_url'));
        self::assertSame(1, DB::table('scenario_events')->where('event_name', 'b2b.sales_call.ready')->count());
        self::assertStringNotContainsString('host', (string) $call->provider_join_url);
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
    public int $createCount = 0;

    public int $updateCount = 0;

    public int $cancelCount = 0;

    public bool $throwAfterCreate = false;

    public bool $failPermanently = false;

    /** @var array<string, VideoMeetingResult> */
    private array $meetings = [];

    /** @var list<string> */
    private array $cancelled = [];

    public function name(): string
    {
        return 'zoom';
    }

    public function createMeeting(Organization $organization, VideoMeetingRequest $request): VideoMeetingResult
    {
        if ($this->failPermanently) {
            throw VideoMeetingException::permanent('zoom_rejected');
        }

        $this->createCount++;
        $identity = new VideoMeetingIdentity('zoom-'.$this->createCount, 'uuid-'.$this->createCount);
        $result = new VideoMeetingResult(
            identity: $identity,
            joinUrl: 'https://zoom.example.test/join/'.$identity->meetingId,
            synchronizedAt: CarbonImmutable::now('UTC'),
        );
        $this->meetings[$request->externalKey] = $result;

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
    ): void {
        $this->updateCount++;
    }

    public function cancelMeeting(Organization $organization, VideoMeetingIdentity $identity): void
    {
        $this->cancelCount++;
        $this->cancelled[] = $identity->meetingId;
    }

    public function obtainHostLaunchUrl(Organization $organization, VideoMeetingIdentity $identity): string
    {
        return 'https://zoom.example.test/host/'.$identity->meetingId;
    }

    public function findMeeting(Organization $organization, VideoMeetingRequest $request): ?VideoMeetingResult
    {
        $result = $this->meetings[$request->externalKey] ?? null;

        if ($result === null || in_array($result->identity->meetingId, $this->cancelled, true)) {
            return null;
        }

        return $result;
    }
}
