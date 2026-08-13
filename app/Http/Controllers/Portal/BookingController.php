<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Http\Requests\CreatePortalBookingRequest;
use App\Http\Requests\PortalBookingQueryRequest;
use App\Modules\ClientPortal\Application\ClientPortalContext;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\ValueObjects\IanaTimezone;
use App\Modules\Scheduling\Application\CalculateAvailability;
use App\Modules\Scheduling\Application\CreateBooking;
use App\Modules\Scheduling\Application\ListBookableServices;
use App\Modules\Scheduling\Application\ListBookableSpecialistsForService;
use App\Modules\Scheduling\Domain\Enums\VisitFormat;
use App\Modules\Services\Domain\Models\Service;
use App\Modules\Specialists\Domain\Models\Specialist;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class BookingController extends Controller
{
    public function create(
        PortalBookingQueryRequest $request,
        ClientPortalContext $clientContext,
        ListBookableServices $services,
        ListBookableSpecialistsForService $specialists,
        CalculateAvailability $availability,
    ): Response {
        $client = $clientContext->client();
        $validated = $request->validated();
        $bookableServices = $services->handle();
        $serviceId = $this->nullableInteger($validated['service_id'] ?? null);
        $selectedService = $serviceId === null
            ? null
            : $bookableServices->first(fn (Service $service): bool => $service->getKey() === $serviceId);
        $bookableSpecialists = $selectedService instanceof Service
            ? $specialists->handle($selectedService->getKey())
            : collect();
        $specialistId = $this->nullableInteger($validated['specialist_id'] ?? null);
        $selectedSpecialist = $specialistId === null
            ? null
            : $bookableSpecialists->first(fn (Specialist $specialist): bool => $specialist->getKey() === $specialistId);
        $displayTimezone = $this->displayTimezone($validated['display_timezone'] ?? null, $client->timezone);
        [$dateFrom, $dateTo] = $this->dateRange($validated, $displayTimezone);
        $format = $this->selectedFormat($validated['format'] ?? null, $selectedService);
        $result = null;

        if ($selectedService instanceof Service
            && $selectedSpecialist !== null
            && in_array($format->value, $selectedService->supportedFormats(), true)) {
            $result = $availability->forClient(
                client: $client,
                specialistId: $selectedSpecialist->getKey(),
                serviceId: $selectedService->getKey(),
                dateFrom: $dateFrom,
                dateTo: $dateTo,
                format: $format,
                displayTimezone: $displayTimezone,
            )->toArray();
        }

        return Inertia::render('Portal/BookingCreate', [
            'services' => $bookableServices->map(fn (Service $service): array => [
                'id' => $service->getKey(),
                'name' => $service->name,
                'summary' => $service->summary,
                'formats' => array_values(array_filter(
                    $service->supportedFormats(),
                    static fn (string $value): bool => VisitFormat::tryFrom($value) instanceof VisitFormat,
                )),
            ])->values()->all(),
            'specialists' => $bookableSpecialists->map(fn (Specialist $specialist): array => [
                'id' => $specialist->getKey(),
                'displayName' => $specialist->display_name,
                'timezone' => $specialist->timezone,
            ])->values()->all(),
            'availability' => $result,
            'query' => [
                'serviceId' => $serviceId,
                'specialistId' => $specialistId,
                'dateFrom' => $dateFrom,
                'dateTo' => $dateTo,
                'format' => $format->value,
                'displayTimezone' => $displayTimezone,
            ],
            'client' => ['timezone' => $client->timezone],
            'bookingResult' => $request->session()->pull('portal_booking_result'),
            'urls' => [
                'create' => route('portal.bookings.create'),
                'store' => route('portal.bookings.store'),
                'services' => route('portal.services.index'),
            ],
        ]);
    }

    public function store(
        CreatePortalBookingRequest $request,
        ClientPortalContext $clientContext,
        OrganizationContext $organizationContext,
        CreateBooking $createBooking,
    ): RedirectResponse {
        $validated = $request->validated();
        $format = VisitFormat::from($validated['format']);

        try {
            $startsAt = CarbonImmutable::parse($validated['starts_at']);
        } catch (Throwable) {
            throw ValidationException::withMessages(['starts_at' => 'The selected time is invalid.']);
        }

        $booking = $createBooking->handle(
            actor: $clientContext->client(),
            client: $clientContext->client(),
            specialist: $this->specialist($validated['specialist_id'], $organizationContext->id()),
            service: $this->service($validated['service_id'], $organizationContext->id()),
            startsAt: $startsAt,
            format: $format,
            clientTimezone: $validated['client_timezone'] ?? null,
        );

        $displayTimezone = $booking->client_timezone ?? $booking->schedule_timezone;

        return redirect()
            ->route('portal.bookings.create', [
                'service_id' => $booking->service_id,
                'specialist_id' => $booking->specialist_id,
                'date_from' => $booking->startsAtUtc()->setTimezone($displayTimezone)->toDateString(),
                'date_to' => $booking->startsAtUtc()->setTimezone($displayTimezone)->toDateString(),
                'format' => $booking->visit_format->value,
                'display_timezone' => $displayTimezone,
            ])
            ->with('portal_booking_result', [
                'status' => $booking->status->value,
            ]);
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array{0: string, 1: string}
     */
    private function dateRange(array $validated, string $timezone): array
    {
        $from = $validated['date_from'] ?? CarbonImmutable::now($timezone)->toDateString();
        $to = $validated['date_to'] ?? CarbonImmutable::parse($from, $timezone)->addDays(6)->toDateString();

        return [$from, $to];
    }

    private function displayTimezone(?string $requested, string $fallback): string
    {
        try {
            return IanaTimezone::from($requested ?? $fallback)->value;
        } catch (Throwable) {
            throw ValidationException::withMessages([
                'display_timezone' => 'The display timezone must be an IANA timezone.',
            ]);
        }
    }

    private function selectedFormat(?string $requested, ?Service $service): VisitFormat
    {
        if ($requested !== null) {
            return VisitFormat::from($requested);
        }

        foreach ($service?->supportedFormats() ?? [] as $format) {
            $visitFormat = VisitFormat::tryFrom($format);

            if ($visitFormat instanceof VisitFormat) {
                return $visitFormat;
            }
        }

        return VisitFormat::Office;
    }

    private function nullableInteger(mixed $value): ?int
    {
        return $value === null || $value === '' ? null : (int) $value;
    }

    private function specialist(int|string $specialistId, int $organizationId): Specialist
    {
        $specialist = Specialist::query()
            ->where('organization_id', $organizationId)
            ->find((int) $specialistId);

        if (! $specialist instanceof Specialist) {
            throw ValidationException::withMessages(['specialist_id' => 'The specialist is not available.']);
        }

        return $specialist;
    }

    private function service(int|string $serviceId, int $organizationId): Service
    {
        $service = Service::query()
            ->where('organization_id', $organizationId)
            ->find((int) $serviceId);

        if (! $service instanceof Service) {
            throw ValidationException::withMessages(['service_id' => 'The service is not available.']);
        }

        return $service;
    }
}
