<?php

namespace App\Modules\Scheduling\Application;

use App\Models\User;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Identity\Domain\Models\ClientBookingRestriction;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Application\OrganizationFeatureGate;
use App\Modules\Organizations\Domain\Enums\OrganizationFeature;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use App\Modules\Organizations\Domain\ValueObjects\IanaTimezone;
use App\Modules\Scheduling\Domain\Enums\BookingEventType;
use App\Modules\Scheduling\Domain\Enums\BookingSource;
use App\Modules\Scheduling\Domain\Enums\BookingStatus;
use App\Modules\Scheduling\Domain\Enums\MeetingLinkMode;
use App\Modules\Scheduling\Domain\Enums\PaymentStatus;
use App\Modules\Scheduling\Domain\Enums\VisitFormat;
use App\Modules\Scheduling\Domain\Models\Booking;
use App\Modules\Scheduling\Domain\Models\BookingEvent;
use App\Modules\Scheduling\Domain\Models\BookingIdempotencyKey;
use App\Modules\Scheduling\Domain\ValueObjects\AvailabilitySlot;
use App\Modules\Security\Application\RecordAuditEvent;
use App\Modules\Services\Domain\Enums\CatalogItemType;
use App\Modules\Services\Domain\Models\Service;
use App\Modules\Specialists\Domain\Models\Specialist;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CreateBooking
{
    public function __construct(
        private readonly OrganizationContext $context,
        private readonly OrganizationAuthorizer $authorizer,
        private readonly OrganizationFeatureGate $features,
        private readonly CalculateAvailability $availability,
        private readonly SpecialistServiceAssignmentEligibility $eligibility,
        private readonly RecordAuditEvent $audit,
    ) {}

    public function handle(
        User|Client $actor,
        Client $client,
        Specialist $specialist,
        Service $service,
        DateTimeInterface $startsAt,
        VisitFormat $format,
        ?string $clientTimezone = null,
        ?MeetingLinkMode $meetingLinkMode = null,
        ?string $idempotencyKey = null,
        int $partySize = 1,
        ?string $location = null,
    ): Booking {
        $organization = $this->context->organization();
        $this->ensureOrganizationOwnership($organization->getKey(), $actor, $client, $specialist, $service);

        $source = $actor instanceof User ? BookingSource::Crm : BookingSource::Portal;

        if ($actor instanceof User) {
            $this->authorizer->authorize($actor, $organization, OrganizationPermission::ManageScheduling);
        } elseif ($actor->getKey() !== $client->getKey()) {
            throw new AuthorizationException('A client may only create a booking for itself.');
        }

        if ($format !== VisitFormat::Online && $meetingLinkMode !== null) {
            throw ValidationException::withMessages(['meetingLinkMode' => 'A meeting-link mode is only valid for online visits.']);
        }

        if ($partySize < 1 || $partySize > (int) config('scheduling.home_visit_max_party_size')) {
            throw ValidationException::withMessages(['partySize' => 'The party size is invalid.']);
        }

        if ($format !== VisitFormat::HomeVisit && $location !== null) {
            throw ValidationException::withMessages(['location' => 'A destination is only valid for home visits.']);
        }

        $requestedStart = CarbonImmutable::instance($startsAt)->utc();
        $idempotencyKey = $this->normalizeIdempotencyKey($idempotencyKey);
        $actorScope = $actor instanceof User ? 'user:'.$actor->getKey() : 'client:'.$actor->getKey();
        $actorType = $actor instanceof User ? 'user' : 'client';
        $requestHash = $this->requestHash(
            clientId: $client->getKey(),
            specialistId: $specialist->getKey(),
            serviceId: $service->getKey(),
            startsAt: $requestedStart,
            format: $format,
            clientTimezone: $clientTimezone,
            meetingLinkMode: $meetingLinkMode,
            partySize: $partySize,
            location: $location,
        );

        return DB::transaction(function () use (
            $actor,
            $client,
            $specialist,
            $service,
            $format,
            $clientTimezone,
            $meetingLinkMode,
            $requestedStart,
            $source,
            $organization,
            $idempotencyKey,
            $actorScope,
            $actorType,
            $partySize,
            $location,
            $requestHash,
        ): Booking {
            $idempotency = $this->lockIdempotencyKey(
                organizationId: $organization->getKey(),
                idempotencyKey: $idempotencyKey,
                actorType: $actorType,
                actorScope: $actorScope,
                actor: $actor,
                requestHash: $requestHash,
            );

            if ($idempotency->booking_id !== null) {
                $idempotency->forceFill(['last_used_at' => now()])->save();

                $bookingQuery = Booking::query()
                    ->where('organization_id', $organization->getKey())
                    ->whereKey($idempotency->booking_id);

                if ($actor instanceof Client) {
                    $bookingQuery->where('client_id', $actor->getKey());
                }

                return $bookingQuery->firstOrFail();
            }

            $this->features->authorize($organization, OrganizationFeature::ServiceCatalog);

            $lockedClient = Client::query()
                ->where('organization_id', $organization->getKey())
                ->whereKey($client->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            $lockedSpecialist = Specialist::query()
                ->where('organization_id', $organization->getKey())
                ->whereKey($specialist->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            $lockedService = Service::query()
                ->where('organization_id', $organization->getKey())
                ->whereKey($service->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($actor instanceof Client && ClientBookingRestriction::query()
                ->where('organization_id', $organization->getKey())
                ->where('client_id', $lockedClient->getKey())
                ->whereNull('unblocked_at')
                ->exists()) {
                throw ValidationException::withMessages([
                    'client' => 'This client is restricted from self-service booking.',
                ]);
            }

            $this->ensureBookableService($lockedService, $format);
            $this->eligibility->ensure(
                $organization->getKey(),
                $lockedSpecialist->getKey(),
                $lockedService->getKey(),
            );

            $resolvedClientTimezone = $this->resolveClientTimezone($clientTimezone, $lockedClient);
            $resolvedMeetingLinkMode = $format === VisitFormat::Online
                ? ($meetingLinkMode ?? MeetingLinkMode::Manual)
                : null;
            $availability = $this->availability->forBooking(
                specialist: $lockedSpecialist,
                service: $lockedService,
                format: $format,
                startsAt: $requestedStart,
                displayTimezone: $resolvedClientTimezone,
            );
            $slot = $this->matchingSlot($availability->slots, $requestedStart);

            if (! $slot instanceof AvailabilitySlot) {
                throw ValidationException::withMessages([
                    'startsAt' => 'The selected time is no longer available.',
                ]);
            }

            $status = $format === VisitFormat::HomeVisit
                ? BookingStatus::PendingReview
                : BookingStatus::Requested;
            $officeLocation = $format === VisitFormat::Office
                ? $organization->settings()
                    ->where('setting_key', 'office_location')
                    ->value('string_value')
                : null;
            $booking = new Booking;
            $booking->forceFill([
                'organization_id' => $organization->getKey(),
                'client_id' => $lockedClient->getKey(),
                'specialist_id' => $lockedSpecialist->getKey(),
                'service_id' => $lockedService->getKey(),
                'calendar_uid' => (string) Str::uuid(),
                'visit_format' => $format,
                'status' => $status,
                'payment_status' => PaymentStatus::Unpaid,
                'source' => $source,
                'starts_at' => $slot->startsAt,
                'ends_at' => $slot->endsAt,
                'blocking_ends_at' => $slot->blockingEndsAt,
                'schedule_timezone' => $slot->scheduleTimezone,
                'client_timezone' => $resolvedClientTimezone,
                'location' => $format === VisitFormat::HomeVisit ? $location : $officeLocation,
                'meeting_link_mode' => $resolvedMeetingLinkMode,
                'party_size' => $partySize,
                'event_version' => 1,
                'requested_at' => now(),
            ]);

            try {
                $booking->save();
            } catch (QueryException $exception) {
                if ($this->isBookingConflict($exception)) {
                    throw ValidationException::withMessages([
                        'startsAt' => 'The selected time is no longer available.',
                    ]);
                }

                throw $exception;
            }

            $event = new BookingEvent;
            $event->forceFill([
                'organization_id' => $organization->getKey(),
                'booking_id' => $booking->getKey(),
                'event_type' => BookingEventType::Created,
                'actor_type' => $actor instanceof User ? 'user' : 'client',
                'actor_user_id' => $actor instanceof User ? $actor->getKey() : null,
                'actor_client_id' => $actor instanceof Client ? $actor->getKey() : null,
                'old_values' => [],
                'new_values' => $this->bookingSnapshot($booking),
                'reason' => null,
                'occurred_at' => now(),
            ]);
            $event->save();

            $idempotency->forceFill([
                'booking_id' => $booking->getKey(),
                'last_used_at' => now(),
            ])->save();

            $this->audit->handle(
                organization: $organization,
                actor: $actor instanceof User ? $actor : null,
                action: 'booking.created',
                targetType: Booking::class,
                targetId: (string) $booking->getKey(),
                metadata: [
                    'source' => $source->value,
                    'visit_format' => $format->value,
                    'status' => $status->value,
                ],
            );

            return $booking->refresh();
        });
    }

    private function ensureOrganizationOwnership(
        int $organizationId,
        User|Client $actor,
        Client $client,
        Specialist $specialist,
        Service $service,
    ): void {
        foreach ([$client, $specialist, $service] as $record) {
            if ((int) $record->organization_id !== $organizationId) {
                throw new AuthorizationException('A scheduling record is outside the current organization.');
            }
        }

        if ($actor instanceof Client && (int) $actor->organization_id !== $organizationId) {
            throw new AuthorizationException('The client is outside the current organization.');
        }
    }

    private function ensureBookableService(Service $service, VisitFormat $format): void
    {
        if (! $service->is_active || $service->catalogItemType() !== CatalogItemType::Service) {
            throw ValidationException::withMessages(['service' => 'The service is not bookable.']);
        }

        if ($service->durationMinutes() === null
            || ! in_array($format->value, $service->supportedFormats(), true)) {
            throw ValidationException::withMessages(['format' => 'The service does not support this visit format.']);
        }
    }

    private function resolveClientTimezone(?string $clientTimezone, Client $client): string
    {
        $timezone = $clientTimezone ?? $client->timezone;

        try {
            return IanaTimezone::from($timezone)->value;
        } catch (\InvalidArgumentException) {
            throw ValidationException::withMessages(['clientTimezone' => 'The client timezone must be an IANA timezone.']);
        }
    }

    /** @param list<AvailabilitySlot> $slots */
    private function matchingSlot(array $slots, CarbonImmutable $requestedStart): ?AvailabilitySlot
    {
        foreach ($slots as $slot) {
            if ($slot->startsAt->equalTo($requestedStart)) {
                return $slot;
            }
        }

        return null;
    }

    /** @return array<string, string|int|null> */
    private function bookingSnapshot(Booking $booking): array
    {
        return [
            'status' => $booking->status->value,
            'payment_status' => $booking->payment_status->value,
            'visit_format' => $booking->visit_format->value,
            'starts_at' => $booking->startsAtUtc()->toIso8601String(),
            'ends_at' => $booking->endsAtUtc()->toIso8601String(),
            'blocking_ends_at' => $booking->blockingEndsAtUtc()->toIso8601String(),
            'schedule_timezone' => $booking->schedule_timezone,
            'client_timezone' => $booking->client_timezone,
            'meeting_link_mode' => $booking->meeting_link_mode?->value,
            'party_size' => $booking->party_size,
            'event_version' => $booking->event_version,
        ];
    }

    private function isBookingConflict(QueryException $exception): bool
    {
        $sqlState = $exception->getCode() ?: ($exception->errorInfo[0] ?? null);

        return in_array($sqlState, ['23P01', '40P01'], true);
    }

    private function normalizeIdempotencyKey(?string $idempotencyKey): string
    {
        $idempotencyKey = trim((string) $idempotencyKey);

        if ($idempotencyKey === '' || mb_strlen($idempotencyKey) > 128) {
            throw ValidationException::withMessages(['idempotencyKey' => 'The idempotency key is invalid.']);
        }

        return $idempotencyKey;
    }

    private function lockIdempotencyKey(
        int $organizationId,
        string $idempotencyKey,
        string $actorType,
        string $actorScope,
        User|Client $actor,
        string $requestHash,
    ): BookingIdempotencyKey {
        DB::table('booking_idempotency_keys')->insertOrIgnore([
            'organization_id' => $organizationId,
            'idempotency_key' => $idempotencyKey,
            'actor_type' => $actorType,
            'actor_scope' => $actorScope,
            'actor_user_id' => $actor instanceof User ? $actor->getKey() : null,
            'actor_client_id' => $actor instanceof Client ? $actor->getKey() : null,
            'request_hash' => $requestHash,
            'booking_id' => null,
            'expires_at' => now()->addDays((int) config('scheduling.idempotency_retention_days')),
            'last_used_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $record = BookingIdempotencyKey::query()
            ->where('organization_id', $organizationId)
            ->where('idempotency_key', $idempotencyKey)
            ->lockForUpdate()
            ->firstOrFail();

        if ($record->actor_scope !== $actorScope || $record->actor_type !== $actorType) {
            throw new AuthorizationException('The idempotency key belongs to another actor.');
        }

        if ($record->request_hash !== $requestHash) {
            throw ValidationException::withMessages([
                'idempotencyKey' => 'The idempotency key was already used for a different booking request.',
            ]);
        }

        return $record;
    }

    private function requestHash(
        int $clientId,
        int $specialistId,
        int $serviceId,
        CarbonImmutable $startsAt,
        VisitFormat $format,
        ?string $clientTimezone,
        ?MeetingLinkMode $meetingLinkMode,
        int $partySize,
        ?string $location,
    ): string {
        return hash('sha256', json_encode([
            'client_id' => $clientId,
            'specialist_id' => $specialistId,
            'service_id' => $serviceId,
            'starts_at' => $startsAt->toIso8601String(),
            'format' => $format->value,
            'client_timezone_intent' => $this->canonicalIntentTimezone($clientTimezone),
            'meeting_link_mode' => $format === VisitFormat::Online
                ? ($meetingLinkMode ?? MeetingLinkMode::Manual)->value
                : null,
            'party_size' => $partySize,
            'location' => $location === null ? null : trim($location),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    private function canonicalIntentTimezone(?string $timezone): ?string
    {
        if ($timezone === null) {
            return null;
        }

        try {
            return IanaTimezone::from(trim($timezone))->value;
        } catch (\InvalidArgumentException) {
            throw ValidationException::withMessages(['clientTimezone' => 'The client timezone must be an IANA timezone.']);
        }
    }
}
