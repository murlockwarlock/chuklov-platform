<?php

namespace App\Modules\Scheduling\Application;

use App\Models\User;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Scheduling\Domain\Enums\MeetingLinkMode;
use App\Modules\Scheduling\Domain\Enums\VisitFormat;
use App\Modules\Scheduling\Domain\Models\Booking;
use App\Modules\Services\Domain\Models\Service;
use App\Modules\Specialists\Domain\Models\Specialist;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

final readonly class CreateMultipleBookings
{
    public const MAX_SLOTS = 20;

    public function __construct(
        private OrganizationContext $context,
        private CreateBooking $createBooking,
        private ResolveSpecialistViewerTimezone $viewerTimezone,
    ) {}

    /**
     * @param  array<int|string, mixed>  $slots
     * @return list<Booking>
     */
    public function handle(
        User $actor,
        Client $client,
        Specialist $specialist,
        Service $service,
        VisitFormat $format,
        array $slots,
        string $batchIntentKey,
        ?MeetingLinkMode $meetingLinkMode = null,
        int $partySize = 1,
        ?string $location = null,
        ?int $workingLocationId = null,
        ?string $locationArea = null,
    ): array {
        $batchIntentKey = $this->normalizeBatchIntentKey($batchIntentKey);
        $normalizedSlots = $this->normalizeSlots($slots, $actor);
        $organizationId = $this->context->id();

        return DB::transaction(function () use (
            $actor,
            $client,
            $specialist,
            $service,
            $format,
            $normalizedSlots,
            $batchIntentKey,
            $meetingLinkMode,
            $partySize,
            $location,
            $workingLocationId,
            $locationArea,
            $organizationId,
        ): array {
            $bookings = [];

            foreach ($normalizedSlots as $slot) {
                try {
                    $bookings[] = $this->createBooking->handle(
                        actor: $actor,
                        client: $client,
                        specialist: $specialist,
                        service: $service,
                        startsAt: $slot['starts_at'],
                        format: $format,
                        clientTimezone: null,
                        meetingLinkMode: $meetingLinkMode,
                        idempotencyKey: $this->slotIdempotencyKey(
                            organizationId: $organizationId,
                            actorId: (int) $actor->getKey(),
                            batchIntentKey: $batchIntentKey,
                            startsAt: $slot['starts_at'],
                        ),
                        partySize: $partySize,
                        location: $location,
                        workingLocationId: $workingLocationId,
                        locationArea: $locationArea,
                        confirmedBackdated: false,
                    );
                } catch (ValidationException $exception) {
                    if (! $this->isUnavailableSlot($exception)) {
                        throw $exception;
                    }

                    throw ValidationException::withMessages([
                        'slots' => [
                            __('Слот :slot больше недоступен. Выберите другое время: :message', [
                                'slot' => $slot['label'],
                                'message' => __('проверьте список свободных интервалов'),
                            ]),
                        ],
                    ]);
                }
            }

            return $bookings;
        });
    }

    private function normalizeBatchIntentKey(string $batchIntentKey): string
    {
        $batchIntentKey = trim($batchIntentKey);

        if ($batchIntentKey === '' || mb_strlen($batchIntentKey) > 128) {
            throw ValidationException::withMessages([
                'batchIntentKey' => __('Не удалось определить операцию создания. Обновите страницу и попробуйте ещё раз.'),
            ]);
        }

        return $batchIntentKey;
    }

    /**
     * @param  array<int|string, mixed>  $slots
     * @return list<array{starts_at: CarbonImmutable, label: string}>
     */
    private function normalizeSlots(array $slots, User $actor): array
    {
        $slots = array_values($slots);
        if (count($slots) < 2) {
            throw ValidationException::withMessages([
                'slots' => __('Выберите минимум два свободных слота.'),
            ]);
        }

        if (count($slots) > self::MAX_SLOTS) {
            throw ValidationException::withMessages([
                'slots' => __('За одну операцию можно создать не более :count записей.', ['count' => self::MAX_SLOTS]),
            ]);
        }

        $timezone = $this->viewerTimezone->forUser($actor);
        $seenStarts = [];
        $normalized = [];

        foreach ($slots as $index => $slot) {
            if (! is_array($slot)) {
                throw ValidationException::withMessages([
                    'slots' => __('Проверьте даты и время выбранных слотов.'),
                ]);
            }

            $date = is_string($slot['date'] ?? null) ? trim($slot['date']) : '';
            $time = is_string($slot['time'] ?? null) ? trim($slot['time']) : '';
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1 || $time === '') {
                throw ValidationException::withMessages([
                    'slots' => __('Проверьте дату и свободное время для слота :number.', ['number' => $index + 1]),
                ]);
            }

            try {
                $startsAt = CarbonImmutable::parse($time)->utc();
            } catch (InvalidArgumentException) {
                throw ValidationException::withMessages([
                    'slots' => __('Проверьте дату и свободное время для слота :number.', ['number' => $index + 1]),
                ]);
            }

            if ($startsAt->setTimezone($timezone)->toDateString() !== $date) {
                throw ValidationException::withMessages([
                    'slots' => __('Дата и время слота :number не совпадают.', ['number' => $index + 1]),
                ]);
            }

            $startsAtKey = $startsAt->toIso8601String();
            if (isset($seenStarts[$startsAtKey])) {
                throw ValidationException::withMessages([
                    'slots' => __('Один и тот же интервал выбран несколько раз.'),
                ]);
            }

            $seenStarts[$startsAtKey] = true;
            $normalized[] = [
                'starts_at' => $startsAt,
                'label' => $startsAt->setTimezone($timezone)->format('d.m.Y H:i'),
            ];
        }

        return $normalized;
    }

    private function slotIdempotencyKey(
        int $organizationId,
        int $actorId,
        string $batchIntentKey,
        CarbonImmutable $startsAt,
    ): string {
        return hash('sha256', implode('|', [
            'booking-batch',
            $organizationId,
            $actorId,
            $batchIntentKey,
            $startsAt->toIso8601String(),
        ]));
    }

    private function isUnavailableSlot(ValidationException $exception): bool
    {
        foreach ($exception->errors() as $messages) {
            foreach ($messages as $message) {
                if ($message === 'The selected time is no longer available.') {
                    return true;
                }
            }
        }

        return false;
    }
}
