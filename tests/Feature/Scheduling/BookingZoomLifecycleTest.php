<?php

namespace Tests\Feature\Scheduling;

use App\Models\User;
use App\Modules\B2B\Application\SyncBookingProvider;
use App\Modules\B2B\Domain\Contracts\VideoMeetingProvider;
use App\Modules\B2B\Domain\ValueObjects\ProviderOperationDeadline;
use App\Modules\B2B\Domain\ValueObjects\VideoMeetingIdentity;
use App\Modules\B2B\Domain\ValueObjects\VideoMeetingRequest;
use App\Modules\B2B\Domain\ValueObjects\VideoMeetingResult;
use App\Modules\B2B\Infrastructure\Video\ZoomVideoMeetingProvider;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Integration\Domain\Enums\IntegrationEventType;
use App\Modules\Integration\Domain\Models\IntegrationEvent;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationFeature;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Organizations\Domain\Models\OrganizationFeatureFlag;
use App\Modules\Scheduling\Application\AssignSpecialistToService;
use App\Modules\Scheduling\Application\CancelBooking;
use App\Modules\Scheduling\Application\CreateBooking;
use App\Modules\Scheduling\Application\RescheduleBooking;
use App\Modules\Scheduling\Application\SetSpecialistWorkingHours;
use App\Modules\Scheduling\Domain\Enums\BookingStatus;
use App\Modules\Scheduling\Domain\Enums\MeetingLinkMode;
use App\Modules\Scheduling\Domain\Enums\VisitFormat;
use App\Modules\Security\Domain\Enums\CredentialStatus;
use App\Modules\Security\Domain\Models\OrganizationCredential;
use App\Modules\Services\Domain\Models\Service;
use App\Modules\Specialists\Domain\Models\Specialist;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class BookingZoomLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private BookingProviderFake $provider;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow(CarbonImmutable::create(2026, 9, 2, 10, 0, 0, 'UTC'));
        Queue::fake();
        $this->provider = new BookingProviderFake;
        $this->app->instance(VideoMeetingProvider::class, $this->provider);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_online_booking_binds_before_provider_event_and_updates_then_cancels_the_same_meeting(): void
    {
        [$organization, $admin, $client, $specialist, $service] = $this->fixture();
        $booking = app(CreateBooking::class)->handle(
            actor: $admin,
            client: $client,
            specialist: $specialist,
            service: $service,
            startsAt: CarbonImmutable::create(2026, 9, 7, 9, 0, 0, 'UTC'),
            format: VisitFormat::Online,
            clientTimezone: 'UTC',
        );

        self::assertSame('account-a', $booking->provider_account_id);
        self::assertSame('host-a', $booking->provider_host_user_id);
        $event = IntegrationEvent::query()
            ->where('event_type', IntegrationEventType::BookingProviderSync)
            ->sole();
        self::assertSame('account-a', $event->payload['provider_account_id']);
        self::assertSame('host-a', $event->payload['provider_host_user_id']);

        app(SyncBookingProvider::class)->handle($event->getKey());
        $booking->refresh();
        self::assertSame(1, $this->provider->createCount);
        self::assertSame(BookingStatus::Requested, $booking->status);
        self::assertSame('ready', $booking->provider_sync_status->value);
        self::assertSame('https://zoom.example.test/join/booking-1', $booking->meeting_url);
        $meetingId = $booking->provider_meeting_id;

        app(RescheduleBooking::class)->handle(
            actor: $admin,
            booking: $booking,
            newStartsAt: CarbonImmutable::create(2026, 9, 7, 14, 0, 0, 'UTC'),
            expectedEventVersion: $booking->event_version,
        );
        $updateEvent = IntegrationEvent::query()
            ->where('event_type', IntegrationEventType::BookingProviderSync)
            ->where('id', '>', $event->getKey())
            ->sole();
        app(SyncBookingProvider::class)->handle($updateEvent->getKey());
        self::assertSame(1, $this->provider->updateCount);
        self::assertSame($meetingId, $booking->refresh()->provider_meeting_id);
        self::assertSame('ready', $booking->provider_sync_status->value);

        app(CancelBooking::class)->handle($admin, $booking->refresh());
        $cancelEvent = IntegrationEvent::query()
            ->where('event_type', IntegrationEventType::BookingProviderSync)
            ->where('id', '>', $updateEvent->getKey())
            ->sole();
        app(SyncBookingProvider::class)->handle($cancelEvent->getKey());
        self::assertSame(1, $this->provider->cancelCount);
        self::assertSame('not_required', $booking->refresh()->provider_sync_status->value);
        self::assertNull($booking->meeting_url);

        app(SyncBookingProvider::class)->handle($cancelEvent->getKey());
        self::assertSame(1, $this->provider->cancelCount);
    }

    public function test_explicit_auto_without_a_complete_zoom_credential_is_rejected_before_booking_creation(): void
    {
        [$organization, $admin, $client, $specialist, $service] = $this->fixture(withCredential: false);

        $this->expectException(ValidationException::class);
        app(CreateBooking::class)->handle(
            actor: $admin,
            client: $client,
            specialist: $specialist,
            service: $service,
            startsAt: CarbonImmutable::create(2026, 9, 7, 11, 0, 0, 'UTC'),
            format: VisitFormat::Online,
            meetingLinkMode: MeetingLinkMode::Auto,
        );
    }

    public function test_initial_booking_create_fails_closed_without_provider_http_after_zoom_account_switch(): void
    {
        [$organization, $admin, $client, $specialist, $service] = $this->fixture();
        $booking = app(CreateBooking::class)->handle(
            actor: $admin,
            client: $client,
            specialist: $specialist,
            service: $service,
            startsAt: CarbonImmutable::create(2026, 9, 7, 11, 30, 0, 'UTC'),
            format: VisitFormat::Online,
            clientTimezone: 'UTC',
        );
        $event = IntegrationEvent::query()
            ->where('event_type', IntegrationEventType::BookingProviderSync)
            ->sole();
        $credential = OrganizationCredential::query()
            ->where('organization_id', $organization->getKey())
            ->sole();
        $credential->forceFill([
            'credentials' => [
                ...$credential->credentials,
                'account_id' => 'account-b',
            ],
        ])->save();
        Http::fake();
        $this->app->instance(VideoMeetingProvider::class, app(ZoomVideoMeetingProvider::class));

        app(SyncBookingProvider::class)->handle($event->getKey());

        $booking->refresh();
        self::assertSame('reconciliation_required', $booking->provider_sync_status->value);
        self::assertSame('zoom_provider_affinity_mismatch', $booking->provider_error_code);
        self::assertSame('failed', $event->fresh()->status->value);
        Http::assertNothingSent();
    }

    /** @return array{Organization, User, Client, Specialist, Service} */
    private function fixture(bool $withCredential = true): array
    {
        $organization = Organization::factory()->create(['timezone' => 'UTC']);
        $admin = User::factory()->forOrganization($organization)->create();
        $client = Client::factory()->forOrganization($organization)->create(['timezone' => 'UTC']);
        $specialist = Specialist::factory()->forOrganization($organization)->create(['timezone' => 'UTC']);
        $service = Service::factory()->forOrganization($organization)->create([
            'duration_minutes' => 60,
            'buffer_minutes' => 15,
            'formats' => ['online'],
        ]);
        config()->set('tenancy.default_organization_id', $organization->getKey());
        app(OrganizationContext::class)->set($organization);
        OrganizationFeatureFlag::factory()->forOrganization($organization)->create([
            'feature_key' => OrganizationFeature::ServiceCatalog->value,
            'enabled' => true,
        ]);
        app(AssignSpecialistToService::class)->handle($admin, $specialist, $service);
        app(SetSpecialistWorkingHours::class)->handle($admin, $specialist, [[
            'weekday' => 1,
            'start_time' => '09:00',
            'end_time' => '17:00',
        ]]);

        if ($withCredential) {
            $credential = OrganizationCredential::factory()->forOrganization($organization)->make([
                'provider' => 'zoom',
                'credential_name' => config('b2b.credential_name'),
                'status' => CredentialStatus::Active,
            ]);
            $credential->forceFill([
                'credentials' => [
                    'account_id' => 'account-a',
                    'client_id' => 'client-a',
                    'client_secret' => 'secret-a',
                    'host_user_id' => 'host-a',
                ],
            ])->save();
        }

        return [$organization, $admin, $client, $specialist, $service];
    }
}

final class BookingProviderFake implements VideoMeetingProvider
{
    public int $createCount = 0;

    public int $updateCount = 0;

    public int $cancelCount = 0;

    /** @var array<string, VideoMeetingResult> */
    private array $meetings = [];

    public function name(): string
    {
        return 'zoom';
    }

    public function createMeeting(
        Organization $organization,
        VideoMeetingRequest $request,
        ProviderOperationDeadline $deadline,
    ): VideoMeetingResult {
        $this->createCount++;
        $identity = new VideoMeetingIdentity('booking-'.$this->createCount, 'uuid-'.$this->createCount, $request->providerAccountAffinity);
        $result = new VideoMeetingResult(
            identity: $identity,
            joinUrl: 'https://zoom.example.test/join/'.$identity->meetingId,
            synchronizedAt: CarbonImmutable::now('UTC'),
            startsAt: $request->startsAt,
            durationMinutes: $request->durationMinutes,
            timezone: $request->timezone,
            agenda: $request->correlationMarker(),
        );
        $this->meetings[$request->externalKey] = $result;

        return $result;
    }

    public function updateMeeting(
        Organization $organization,
        VideoMeetingIdentity $identity,
        VideoMeetingRequest $request,
        ProviderOperationDeadline $deadline,
    ): void {
        $this->updateCount++;
        $this->meetings[$request->externalKey] = new VideoMeetingResult(
            identity: $identity,
            joinUrl: 'https://zoom.example.test/join/'.$identity->meetingId,
            synchronizedAt: CarbonImmutable::now('UTC'),
            startsAt: $request->startsAt,
            durationMinutes: $request->durationMinutes,
            timezone: $request->timezone,
            agenda: $request->correlationMarker(),
        );
    }

    public function cancelMeeting(
        Organization $organization,
        VideoMeetingIdentity $identity,
        VideoMeetingRequest $request,
        ProviderOperationDeadline $deadline,
    ): void {
        $this->cancelCount++;
        unset($this->meetings[$request->externalKey]);
    }

    public function obtainHostLaunchUrl(
        Organization $organization,
        VideoMeetingIdentity $identity,
        VideoMeetingRequest $request,
        ProviderOperationDeadline $deadline,
    ): string {
        return 'https://zoom.example.test/start/'.$identity->meetingId;
    }

    public function findMeeting(
        Organization $organization,
        VideoMeetingRequest $request,
        ProviderOperationDeadline $deadline,
    ): ?VideoMeetingResult {
        return $this->meetings[$request->externalKey] ?? null;
    }

    public function getMeeting(
        Organization $organization,
        VideoMeetingIdentity $identity,
        VideoMeetingRequest $request,
        ProviderOperationDeadline $deadline,
    ): ?VideoMeetingResult {
        $result = $this->meetings[$request->externalKey] ?? null;

        return $result?->identity->meetingId === $identity->meetingId ? $result : null;
    }
}
