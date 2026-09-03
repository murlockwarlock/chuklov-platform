<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Http\Requests\CreatePortalBookingRequest;
use App\Http\Requests\PortalBookingActionRequest;
use App\Http\Requests\PortalBookingQueryRequest;
use App\Http\Requests\PortalBookingRescheduleRequest;
use App\Http\Requests\PortalTimezonePreferenceRequest;
use App\Modules\Attribution\Application\GetClientAttribution;
use App\Modules\ClientPortal\Application\ClientPortalContext;
use App\Modules\ClientPortal\Application\CreatePortalBooking;
use App\Modules\ClientPortal\Application\PortalBookingErrorMessages;
use App\Modules\ClientPortal\Application\ProjectPortalService;
use App\Modules\Identity\Application\ListPublishedLegalDocuments;
use App\Modules\Identity\Domain\Enums\ConsentSubject;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Application\OrganizationFeatureGate;
use App\Modules\Organizations\Domain\Enums\OrganizationFeature;
use App\Modules\Organizations\Domain\ValueObjects\IanaTimezone;
use App\Modules\Scheduling\Application\CalculateAvailability;
use App\Modules\Scheduling\Application\CancelBooking;
use App\Modules\Scheduling\Application\ListBookableServices;
use App\Modules\Scheduling\Application\ListBookableSpecialistsForService;
use App\Modules\Scheduling\Application\ListClientBookings;
use App\Modules\Scheduling\Application\RescheduleBooking;
use App\Modules\Scheduling\Application\UpdateClientTimezonePreference;
use App\Modules\Scheduling\Domain\Enums\VisitFormat;
use App\Modules\Services\Domain\Models\Service;
use App\Modules\Specialists\Domain\Models\Specialist;
use App\Support\RichText\RichTextDocument;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class BookingController extends Controller
{
    public function __construct(private readonly PortalBookingErrorMessages $bookingErrors) {}

    public function create(
        PortalBookingQueryRequest $request,
        ClientPortalContext $clientContext,
        ListBookableServices $services,
        ListBookableSpecialistsForService $specialists,
        CalculateAvailability $availability,
        ProjectPortalService $serviceProjection,
        ListPublishedLegalDocuments $legalDocuments,
        GetClientAttribution $getAttribution,
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

        if ($selectedSpecialist === null && $bookableSpecialists->count() === 1) {
            $selectedSpecialist = $bookableSpecialists->first();
            $specialistId = $selectedSpecialist instanceof Specialist
                ? (int) $selectedSpecialist->getKey()
                : null;
        }
        $displayTimezone = $this->displayTimezone($validated['display_timezone'] ?? null, $client->timezone);
        [$dateFrom, $dateTo] = $this->dateRange($validated, $displayTimezone);
        $format = $this->selectedFormat($validated['format'] ?? null, $selectedService);
        $result = null;

        if ($selectedService instanceof Service
            && $selectedSpecialist !== null
            && in_array($format->value, $selectedService->supportedFormats(), true)) {
            try {
                $result = $availability->forClient(
                    client: $client,
                    specialistId: $selectedSpecialist->getKey(),
                    serviceId: $selectedService->getKey(),
                    dateFrom: $dateFrom,
                    dateTo: $dateTo,
                    format: $format,
                    displayTimezone: $displayTimezone,
                )->toArray();
            } catch (ValidationException $exception) {
                throw ValidationException::withMessages($this->bookingErrors->availabilityErrors($exception));
            }
        }

        return Inertia::render('Portal/BookingCreate', [
            'services' => $bookableServices->map(fn (Service $service): array => [
                ...$serviceProjection->booking($service, app()->getLocale()),
                'formats' => array_values(array_filter(
                    $service->supportedFormats(),
                    static fn (string $value): bool => VisitFormat::tryFrom($value) instanceof VisitFormat,
                )),
            ])->values()->all(),
            'specialists' => $bookableSpecialists->map(fn (Specialist $specialist): array => [
                'id' => $specialist->getKey(),
                'displayName' => $specialist->display_name,
            ])->values()->all(),
            'availability' => $result,
            'query' => [
                'serviceId' => $serviceId,
                'specialistId' => $specialistId,
                'dateFrom' => $dateFrom,
                'dateTo' => $dateTo,
                'format' => $format->value,
                'formatSelected' => ($validated['format'] ?? null) !== null,
                'displayTimezone' => $displayTimezone,
            ],
            'bookingResult' => $request->session()->pull('portal_booking_result'),
            'legalDocuments' => $legalDocuments->handle($client->language)->map(function ($document): array {
                $subject = ConsentSubject::tryFrom($document->document_type);

                return [
                    'id' => $document->getKey(),
                    'title' => $subject?->label(str_starts_with(strtolower((string) $document->locale), 'ru') ? 'ru' : 'en') ?? $document->purpose,
                    'content' => $document->content,
                    'contentHtml' => RichTextDocument::canonicalHtml($document->content),
                    'version' => $document->version,
                    'documentType' => $document->document_type,
                    'isRequired' => $subject?->isRequired() ?? false,
                ];
            })->values()->all(),
            'attribution' => [
                'needsManualSource' => $getAttribution->handle($client) === null,
                'url' => route('portal.attribution.update'),
                'sources' => array_values(config('attribution.manual_sources', [])),
            ],
            'urls' => [
                'create' => route('portal.bookings.create'),
                'store' => route('portal.bookings.store'),
                'services' => route('portal.services.index'),
                'bookings' => route('portal.bookings.index'),
                'referrals' => route('portal.referrals'),
            ],
        ]);
    }

    public function store(
        CreatePortalBookingRequest $request,
        ClientPortalContext $clientContext,
        OrganizationContext $organizationContext,
        CreatePortalBooking $createBooking,
    ): RedirectResponse {
        $validated = $request->validated();
        $format = VisitFormat::from($validated['format']);

        try {
            $startsAt = CarbonImmutable::parse($validated['starts_at']);
        } catch (Throwable) {
            throw ValidationException::withMessages([
                'starts_at' => $this->bookingErrors->message('date_time_invalid'),
            ]);
        }

        try {
            $booking = $createBooking->handle(
                client: $clientContext->client(),
                specialist: $this->specialist($validated['specialist_id'], $organizationContext->id()),
                service: $this->service($validated['service_id'], $organizationContext->id()),
                startsAt: $startsAt,
                format: $format,
                consents: $validated['consents'] ?? [],
                marketingConsent: (bool) ($validated['marketing_consent'] ?? false),
                clientTimezone: $clientContext->client()->timezone,
                partySize: (int) ($validated['party_size'] ?? 1),
                location: isset($validated['location']) ? (string) $validated['location'] : null,
                attributionSource: filled($validated['attribution_source'] ?? null)
                    ? (string) $validated['attribution_source']
                    : null,
            );
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages($this->bookingErrors->bookingErrors($exception));
        }
        $displayTimezone = $clientContext->client()->timezone;

        return redirect()
            ->route('portal.bookings.create', [
                'service_id' => $booking->service_id,
                'specialist_id' => $booking->specialist_id,
                'date_from' => $booking->startsAtUtc()->setTimezone($displayTimezone)->toDateString(),
                'date_to' => $booking->startsAtUtc()->setTimezone($displayTimezone)->toDateString(),
                'format' => $booking->visit_format->value,
            ])
            ->with('portal_booking_result', [
                'message' => $booking->visit_format === VisitFormat::HomeVisit
                    ? $this->bookingErrors->message('request_sent')
                    : $this->bookingErrors->message('booking_created'),
                'bookingId' => $booking->getKey(),
                'startsAt' => $booking->startsAtUtc()->toIso8601String(),
            ]);
    }

    public function index(ListClientBookings $bookings): Response
    {
        return Inertia::render('Portal/MyBookings', [
            ...$bookings->handle(app()->getLocale()),
            'urls' => [
                'create' => route('portal.bookings.create'),
                'services' => route('portal.services.index'),
            ],
        ]);
    }

    public function show(
        int $bookingId,
        PortalBookingQueryRequest $request,
        ListClientBookings $bookings,
        CalculateAvailability $availability,
        ClientPortalContext $clientContext,
        OrganizationFeatureGate $features,
    ): Response {
        $booking = $bookings->find($bookingId);
        abort_unless($booking !== null, 404);
        $client = $clientContext->client();
        $displayTimezone = $client->timezone;
        $availabilityProjection = null;
        $availabilityRange = null;
        $bookingProjection = $bookings->projection($booking, app()->getLocale());
        $validated = $request->validated();

        if ($request->boolean('reschedule')
            && ($bookingProjection['canReschedule'] ?? false) === true
            && ! in_array($booking->status->value, ['rejected', 'cancelled', 'completed', 'no_show'], true)) {
            $localDate = $booking->startsAtUtc()->setTimezone($displayTimezone);
            [$dateFrom, $dateTo] = $this->calendarMonthRange(
                $validated,
                $displayTimezone,
                $localDate->toDateString(),
            );

            if ($features->isEnabled($client->organization, OrganizationFeature::ServiceCatalog)) {
                try {
                    $availabilityProjection = $availability->forClient(
                        client: $client,
                        specialistId: $booking->specialist_id,
                        serviceId: $booking->service_id,
                        dateFrom: $dateFrom,
                        dateTo: $dateTo,
                        format: $booking->visit_format,
                        displayTimezone: $displayTimezone,
                    )->toArray();
                } catch (ValidationException $exception) {
                    throw ValidationException::withMessages($this->bookingErrors->availabilityErrors($exception));
                }
                $availabilityRange = [
                    'dateFrom' => $dateFrom,
                    'dateTo' => $dateTo,
                ];
            }
        }

        return Inertia::render('Portal/BookingShow', [
            'booking' => $bookingProjection,
            'availability' => $availabilityProjection,
            'availabilityRange' => $availabilityRange,
            'urls' => [
                'index' => route('portal.bookings.index'),
                'show' => route('portal.bookings.show', $booking->getKey()),
                'cancel' => route('portal.bookings.cancel', $booking->getKey()),
                'reschedule' => route('portal.bookings.reschedule', $booking->getKey()),
                'timezone' => route('portal.preferences.timezone'),
                'services' => route('portal.services.index'),
            ],
            'client' => ['timezone' => $client->timezone],
        ]);
    }

    public function cancel(
        PortalBookingActionRequest $request,
        int $bookingId,
        ListClientBookings $bookings,
        ClientPortalContext $clientContext,
        CancelBooking $cancelBooking,
    ): RedirectResponse {
        $booking = $bookings->find($bookingId);
        abort_unless($booking !== null, 404);
        try {
            $cancelBooking->handle($clientContext->client(), $booking, $request->validated()['reason'] ?? null);
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages($this->bookingErrors->bookingActionErrors($exception));
        }

        return to_route('portal.bookings.show', $bookingId);
    }

    public function reschedule(
        PortalBookingRescheduleRequest $request,
        int $bookingId,
        ListClientBookings $bookings,
        ClientPortalContext $clientContext,
        RescheduleBooking $rescheduleBooking,
        UpdateClientTimezonePreference $timezonePreference,
    ): RedirectResponse {
        $booking = $bookings->find($bookingId);
        abort_unless($booking !== null, 404);
        $validated = $request->validated();

        try {
            $startsAt = CarbonImmutable::parse($validated['starts_at']);
        } catch (Throwable) {
            throw ValidationException::withMessages([
                'starts_at' => $this->bookingErrors->message('date_time_invalid'),
            ]);
        }

        $clientTimezone = $validated['client_timezone'] ?? null;
        try {
            $rescheduleBooking->handle(
                actor: $clientContext->client(),
                booking: $booking,
                newStartsAt: $startsAt,
                clientTimezone: is_string($clientTimezone) ? $clientTimezone : null,
                reason: isset($validated['reason']) ? (string) $validated['reason'] : null,
                expectedEventVersion: (int) $validated['expected_event_version'],
            );
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages($this->bookingErrors->rescheduleErrors($exception));
        }
        if (is_string($clientTimezone)) {
            $timezonePreference->handle($clientTimezone);
        }

        return to_route('portal.bookings.show', $bookingId);
    }

    public function updateTimezone(
        PortalTimezonePreferenceRequest $request,
        UpdateClientTimezonePreference $timezonePreference,
    ): RedirectResponse {
        try {
            $timezonePreference->handle((string) $request->validated('timezone'));
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages($this->bookingErrors->timezoneErrors($exception));
        }

        return back();
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array{0: string, 1: string}
     */
    private function dateRange(array $validated, string $timezone): array
    {
        return $this->calendarMonthRange($validated, $timezone);
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array{0: string, 1: string}
     */
    private function calendarMonthRange(array $validated, string $timezone, ?string $fallbackDate = null): array
    {
        $requestedDate = $validated['date_from'] ?? null;
        $anchor = is_string($requestedDate)
            ? $requestedDate
            : ($fallbackDate ?? CarbonImmutable::now($timezone)->toDateString());
        $month = CarbonImmutable::parse($anchor, $timezone)->startOfMonth();

        return [$month->toDateString(), $month->endOfMonth()->toDateString()];
    }

    private function displayTimezone(?string $requested, string $fallback): string
    {
        try {
            return IanaTimezone::from($requested ?? $fallback)->value;
        } catch (Throwable) {
            throw ValidationException::withMessages([
                'display_timezone' => $this->bookingErrors->message('timezone_detect_failed'),
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
            throw ValidationException::withMessages([
                'specialist_id' => $this->bookingErrors->message('specialist_unavailable'),
            ]);
        }

        return $specialist;
    }

    private function service(int|string $serviceId, int $organizationId): Service
    {
        $service = Service::query()
            ->where('organization_id', $organizationId)
            ->find((int) $serviceId);

        if (! $service instanceof Service) {
            throw ValidationException::withMessages([
                'service_id' => $this->bookingErrors->message('service_unavailable'),
            ]);
        }

        return $service;
    }
}
