<?php

namespace Database\Factories;

use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Scheduling\Domain\Enums\ScheduleExceptionType;
use App\Modules\Scheduling\Domain\Models\ScheduleException;
use App\Modules\Specialists\Domain\Models\Specialist;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ScheduleException> */
class ScheduleExceptionFactory extends Factory
{
    protected $model = ScheduleException::class;

    public function definition(): array
    {
        return [
            'exception_date' => now()->addDays(3)->toDateString(),
            'exception_type' => ScheduleExceptionType::DayOff->value,
            'start_time' => null,
            'end_time' => null,
            'reason' => 'Unavailable',
            'is_active' => true,
        ];
    }

    public function customWindow(string $start = '10:00', string $end = '14:00'): static
    {
        return $this->state(fn (): array => [
            'exception_type' => ScheduleExceptionType::CustomWindow->value,
            'start_time' => $start,
            'end_time' => $end,
        ]);
    }

    public function forOrganization(Organization $organization): static
    {
        return $this->afterMaking(fn (ScheduleException $exception): ScheduleException => $exception->forceFill([
            'organization_id' => $organization->getKey(),
        ]));
    }

    public function forSpecialist(Specialist $specialist): static
    {
        return $this->afterMaking(fn (ScheduleException $exception): ScheduleException => $exception->forceFill([
            'organization_id' => $specialist->organization_id,
            'specialist_id' => $specialist->getKey(),
        ]));
    }
}
