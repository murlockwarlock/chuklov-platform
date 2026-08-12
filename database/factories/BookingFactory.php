<?php

namespace Database\Factories;

use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Scheduling\Domain\Enums\BookingSource;
use App\Modules\Scheduling\Domain\Enums\BookingStatus;
use App\Modules\Scheduling\Domain\Enums\PaymentStatus;
use App\Modules\Scheduling\Domain\Enums\VisitFormat;
use App\Modules\Scheduling\Domain\Models\Booking;
use App\Modules\Services\Domain\Models\Service;
use App\Modules\Specialists\Domain\Models\Specialist;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Booking> */
class BookingFactory extends Factory
{
    protected $model = Booking::class;

    public function definition(): array
    {
        $start = now()->addDays(3)->setTime(10, 0);

        return [
            'calendar_uid' => fake()->uuid(),
            'visit_format' => VisitFormat::Office->value,
            'status' => BookingStatus::Confirmed->value,
            'payment_status' => PaymentStatus::Unpaid->value,
            'source' => BookingSource::Crm->value,
            'starts_at' => $start,
            'ends_at' => $start->copy()->addHour(),
            'blocking_ends_at' => $start->copy()->addHour(),
            'schedule_timezone' => 'UTC',
            'client_timezone' => null,
            'location' => null,
            'meeting_link_mode' => null,
            'meeting_url' => null,
            'party_size' => 1,
            'event_version' => 1,
            'requested_at' => now(),
            'cancelled_at' => null,
        ];
    }

    public function forOrganization(Organization $organization): static
    {
        return $this->afterMaking(fn (Booking $booking): Booking => $booking->forceFill([
            'organization_id' => $organization->getKey(),
        ]));
    }

    public function forClient(Client $client): static
    {
        return $this->afterMaking(fn (Booking $booking): Booking => $booking->forceFill([
            'organization_id' => $client->organization_id,
            'client_id' => $client->getKey(),
        ]));
    }

    public function forSpecialist(Specialist $specialist): static
    {
        return $this->afterMaking(fn (Booking $booking): Booking => $booking->forceFill([
            'organization_id' => $specialist->organization_id,
            'specialist_id' => $specialist->getKey(),
        ]));
    }

    public function forService(Service $service): static
    {
        return $this->afterMaking(fn (Booking $booking): Booking => $booking->forceFill([
            'organization_id' => $service->organization_id,
            'service_id' => $service->getKey(),
        ]));
    }
}
