<?php

namespace App\Modules\Scheduling\Application;

use App\Models\User;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Identity\Domain\Models\ClientBookingRestriction;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
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
        private readonly CalculateAvailability $availability,
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

        $requestedStart = CarbonImmutable::instance($startsAt)->utc();

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
        ): Booking {
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

            $resolvedClientTimezone = $this->resolveClientTimezone($clientTimezone, $lockedClient);
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
                'meeting_link_mode' => $meetingLinkMode,
                'party_size' => 1,
                'event_version' => 1,
                'requested_at' => now(),
            ]);

            try {
                $booking->save();
            } catch (QueryException $exception) {
                if ($this->isExclusionViolation($exception)) {
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

    /** @return array<string, string|int> */
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
            'event_version' => $booking->event_version,
        ];
    }

    private function isExclusionViolation(QueryException $exception): bool
    {
        return $exception->getCode() === '23P01' || ($exception->errorInfo[0] ?? null) === '23P01';
    }
}
