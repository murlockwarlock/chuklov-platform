<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Http\Requests\CreatePortalBookingRequest;
use App\Http\Requests\PortalBookingActionRequest;
use App\Http\Requests\PortalBookingQueryRequest;
use App\Http\Requests\PortalBookingRescheduleRequest;
use App\Http\Requests\PortalTimezonePreferenceRequest;
use App\Modules\ClientPortal\Application\ClientPortalContext;
use App\Modules\ClientPortal\Application\ProjectPortalService;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Application\OrganizationFeatureGate;
use App\Modules\Organizations\Domain\Enums\OrganizationFeature;
use App\Modules\Organizations\Domain\ValueObjects\IanaTimezone;
use App\Modules\Scheduling\Application\CalculateAvailability;
use App\Modules\Scheduling\Application\CancelBooking;
use App\Modules\Scheduling\Application\CreateBooking;
use App\Modules\Scheduling\Application\ListBookableServices;
use App\Modules\Scheduling\Application\ListBookableSpecialistsForService;
use App\Modules\Scheduling\Application\ListClientBookings;
use App\Modules\Scheduling\Application\RescheduleBooking;
use App\Modules\Scheduling\Application\UpdateClientTimezonePreference;
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
        ProjectPortalService $serviceProjection,
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
            'urls' => [
                'create' => route('portal.bookings.create'),
                'store' => route('portal.bookings.store'),
                'services' => route('portal.services.index'),
                'bookings' => route('portal.bookings.index'),
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
            throw ValidationException::withMessages(['starts_at' => 'Выбранное время недействительно.']);
        }

        try {
            $booking = $createBooking->handle(
                actor: $clientContext->client(),
                client: $clientContext->client(),
                specialist: $this->specialist($validated['specialist_id'], $organizationContext->id()),
                service: $this->service($validated['service_id'], $organizationContext->id()),
                startsAt: $startsAt,
                format: $format,
                clientTimezone: null,
                meetingLinkMode: null,
                idempotencyKey: null,
                partySize: (int) ($validated['party_size'] ?? 1),
                location: isset($validated['location']) ? (string) $validated['location'] : null,
            );
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages($this->portalBookingErrors($exception));
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
                    ? $this->localizedMessage(
                        'Заявка отправлена. Мы подтвердим время отдельно.',
                        'Request sent. We will confirm the time separately.',
                    )
                    : $this->localizedMessage('Запись создана.', 'Booking created.'),
                'bookingId' => $booking->getKey(),
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

        if ($request->boolean('reschedule')
            && ($bookingProjection['canReschedule'] ?? false) === true
            && ! in_array($booking->status->value, ['rejected', 'cancelled', 'completed', 'no_show'], true)) {
            $localDate = $booking->startsAtUtc()->setTimezone($displayTimezone);
            $dateFrom = $request->validated('date_from') ?? $localDate->startOfMonth()->toDateString();
            $dateTo = $request->validated('date_to') ?? $localDate->endOfMonth()->toDateString();

            if ($features->isEnabled($client->organization, OrganizationFeature::ServiceCatalog)) {
                $availabilityProjection = $availability->forClient(
                    client: $client,
                    specialistId: $booking->specialist_id,
                    serviceId: $booking->service_id,
                    dateFrom: $dateFrom,
                    dateTo: $dateTo,
                    format: $booking->visit_format,
                    displayTimezone: $displayTimezone,
                )->toArray();
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
            throw ValidationException::withMessages($this->portalBookingActionErrors($exception));
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
            throw ValidationException::withMessages(['starts_at' => 'Выбранное время недействительно.']);
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
            throw ValidationException::withMessages($this->portalRescheduleErrors($exception));
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
        $timezonePreference->handle((string) $request->validated('timezone'));

        return back();
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
                'display_timezone' => 'Не удалось определить часовой пояс. Обновите страницу.',
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

    private function localizedMessage(string $russian, string $english): string
    {
        return app()->getLocale() === 'en' ? $english : $russian;
    }

    private function specialist(int|string $specialistId, int $organizationId): Specialist
    {
        $specialist = Specialist::query()
            ->where('organization_id', $organizationId)
            ->find((int) $specialistId);

        if (! $specialist instanceof Specialist) {
            throw ValidationException::withMessages(['specialist_id' => 'Сейчас для этой услуги нет доступного специалиста.']);
        }

        return $specialist;
    }

    private function service(int|string $serviceId, int $organizationId): Service
    {
        $service = Service::query()
            ->where('organization_id', $organizationId)
            ->find((int) $serviceId);

        if (! $service instanceof Service) {
            throw ValidationException::withMessages(['service_id' => 'Эта услуга сейчас недоступна.']);
        }

        return $service;
    }

    /** @return array<string, list<string>> */
    private function portalBookingErrors(ValidationException $exception): array
    {
        $errors = [];

        foreach ($exception->errors() as $field => $_messages) {
            $displayField = match ($field) {
                'startsAt' => 'starts_at',
                'partySize' => 'party_size',
                'client' => 'starts_at',
                'service' => 'service_id',
                'specialist' => 'specialist_id',
                default => $field,
            };
            $errors[$displayField][] = match ($field) {
                'startsAt' => 'Это время уже недоступно. Выберите другое.',
                'client' => 'Самостоятельная запись сейчас недоступна. Свяжитесь с нами.',
                'service' => 'Эта услуга сейчас недоступна.',
                'specialist' => 'Сейчас для этой услуги нет доступного специалиста.',
                'format' => 'Выберите другой формат для этой услуги.',
                'partySize' => 'Укажите количество человек.',
                'location' => 'Укажите адрес выезда.',
                default => 'Не удалось создать запись. Попробуйте ещё раз.',
            };
        }

        return $errors;
    }

    /** @return array<string, list<string>> */
    private function portalRescheduleErrors(ValidationException $exception): array
    {
        $errors = [];

        foreach ($exception->errors() as $field => $_messages) {
            $displayField = $field === 'startsAt' ? 'starts_at' : $field;
            $errors[$displayField][] = match ($field) {
                'startsAt' => 'Это время уже недоступно. Выберите другое.',
                'booking' => 'Перенести запись онлайн уже нельзя. Свяжитесь с нами.',
                'expected_event_version' => 'Запись изменилась. Обновите страницу и выберите время ещё раз.',
                default => 'Не удалось перенести запись. Попробуйте ещё раз.',
            };
        }

        return $errors;
    }

    /** @return array<string, list<string>> */
    private function portalBookingActionErrors(ValidationException $exception): array
    {
        $errors = [];

        foreach ($exception->errors() as $field => $_messages) {
            $errors[$field][] = match ($field) {
                'booking' => 'Отменить запись онлайн уже нельзя. Свяжитесь с нами.',
                default => 'Не удалось изменить запись. Попробуйте ещё раз.',
            };
        }

        return $errors;
    }
}
