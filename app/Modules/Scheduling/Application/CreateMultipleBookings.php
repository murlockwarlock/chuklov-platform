<?php

namespace App\Modules\Scheduling\Application;

use App\Models\User;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\ValueObjects\IanaTimezone;
use App\Modules\Scheduling\Domain\Enums\MeetingLinkMode;
use App\Modules\Scheduling\Domain\Enums\VisitFormat;
use App\Modules\Scheduling\Domain\Models\Booking;
use App\Modules\Scheduling\Domain\Models\BookingIdempotencyKey;
use App\Modules\Services\Domain\Models\Service;
use App\Modules\Specialists\Domain\Models\Specialist;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
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
        User|Client $actor,
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
        ?string $clientTimezone = null,
        ?float $latitude = null,
        ?float $longitude = null,
        ?string $mapUrl = null,
        ?Closure $beforeCreate = null,
        ?string $additionalIntentHash = null,
        ?int $patientProfileId = null,
    ): array {
        $batchIntentKey = $this->normalizeBatchIntentKey($batchIntentKey);
        $normalizedSlots = $this->normalizeSlots($slots, $actor, $clientTimezone);
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
            $clientTimezone,
            $latitude,
            $longitude,
            $mapUrl,
            $beforeCreate,
            $additionalIntentHash,
            $patientProfileId,
        ): array {
            $intent = $actor instanceof Client ? $this->lockClientIntent(
                $actor, $client, $specialist, $service, $format, $normalizedSlots, $batchIntentKey,
                $partySize, $location, $workingLocationId, $locationArea, $clientTimezone, $latitude, $longitude, $mapUrl,
                $additionalIntentHash,
                $patientProfileId,
            ) : null;
            $bookings = [];

            foreach ($normalizedSlots as $index => $slot) {
                try {
                    $bookings[] = $this->createBooking->handle(
                        actor: $actor,
                        client: $client,
                        specialist: $specialist,
                        service: $service,
                        startsAt: $slot['starts_at'],
                        format: $format,
                        clientTimezone: $clientTimezone,
                        meetingLinkMode: $meetingLinkMode,
                        idempotencyKey: $this->slotIdempotencyKey(
                            organizationId: $organizationId,
                            actorId: (int) $actor->getKey(),
                            batchIntentKey: $batchIntentKey,
                            startsAt: $slot['starts_at'],
                            isClient: $actor instanceof Client,
                        ),
                        partySize: $partySize,
                        location: $location,
                        workingLocationId: $workingLocationId,
                        locationArea: $locationArea,
                        latitude: $latitude,
                        longitude: $longitude,
                        mapUrl: $mapUrl,
                        beforeCreate: $index === 0 ? $beforeCreate : null,
                        confirmedBackdated: false,
                        additionalIntentHash: $additionalIntentHash,
                        patientProfileId: $patientProfileId,
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

            $intent?->forceFill(['booking_id' => $bookings[0]->getKey(), 'last_used_at' => now()])->save();

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
    private function normalizeSlots(array $slots, User|Client $actor, ?string $clientTimezone): array
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

        try {
            $timezone = $actor instanceof User
                ? $this->viewerTimezone->forUser($actor)
                : IanaTimezone::from($clientTimezone ?? $actor->timezone ?? $this->context->organization()->defaultTimezone())->value;
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages(['clientTimezone' => __('Выберите корректный часовой пояс.')]);
        }
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
        bool $isClient,
    ): string {
        return hash('sha256', implode('|', [
            $isClient ? 'portal-booking-batch' : 'booking-batch',
            $organizationId,
            $actorId,
            $batchIntentKey,
            $startsAt->toIso8601String(),
        ]));
    }

    /** @param list<array{starts_at: CarbonImmutable, label: string}> $slots */
    private function lockClientIntent(
        Client $actor,
        Client $client,
        Specialist $specialist,
        Service $service,
        VisitFormat $format,
        array $slots,
        string $batchIntentKey,
        int $partySize,
        ?string $location,
        ?int $workingLocationId,
        ?string $locationArea,
        ?string $clientTimezone,
        ?float $latitude,
        ?float $longitude,
        ?string $mapUrl,
        ?string $additionalIntentHash,
        ?int $patientProfileId,
    ): BookingIdempotencyKey {
        $organizationId = $this->context->id();
        if ($actor->getKey() !== $client->getKey()
            || (int) $actor->organization_id !== $organizationId
            || (int) $specialist->organization_id !== $organizationId
            || (int) $service->organization_id !== $organizationId) {
            throw new AuthorizationException;
        }
        $starts = array_map(static fn (array $slot): string => $slot['starts_at']->toIso8601String(), $slots);
        sort($starts);
        $requestHash = hash('sha256', json_encode([
            $client->getKey(), $specialist->getKey(), $service->getKey(), $format->value, $starts,
            $partySize, $location, $workingLocationId, $locationArea, $clientTimezone, $latitude, $longitude, $mapUrl,
            ...$additionalIntentHash === null ? [] : [$additionalIntentHash],
            ...$patientProfileId === null ? [] : [$patientProfileId],
        ], JSON_THROW_ON_ERROR));
        $key = hash('sha256', 'portal-booking-batch-intent|'.$actor->getKey().'|'.$batchIntentKey);
        DB::table('booking_idempotency_keys')->insertOrIgnore([
            'organization_id' => $organizationId, 'idempotency_key' => $key,
            'actor_type' => 'client', 'actor_scope' => 'client:'.$actor->getKey(),
            'actor_client_id' => $actor->getKey(), 'request_hash' => $requestHash,
            'expires_at' => now()->addDays((int) config('scheduling.idempotency_retention_days')),
            'last_used_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $intent = BookingIdempotencyKey::query()->where('organization_id', $organizationId)
            ->where('idempotency_key', $key)->lockForUpdate()->firstOrFail();
        if ($intent->actor_type !== 'client' || $intent->actor_scope !== 'client:'.$actor->getKey()) {
            throw new AuthorizationException;
        }
        if ($intent->request_hash !== $requestHash) {
            throw ValidationException::withMessages(['batchIntentKey' => __('Набор сеансов изменился. Проверьте выбранное время и подтвердите запись заново.')]);
        }

        return $intent;
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
