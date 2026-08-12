<?php

namespace Database\Factories;

use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Scheduling\Domain\Models\UnavailablePeriod;
use App\Modules\Specialists\Domain\Models\Specialist;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<UnavailablePeriod> */
class UnavailablePeriodFactory extends Factory
{
    protected $model = UnavailablePeriod::class;

    public function definition(): array
    {
        $start = now()->addDays(3)->setTime(12, 0);

        return [
            'starts_at' => $start,
            'ends_at' => $start->copy()->addHour(),
            'reason' => 'Unavailable',
            'created_by_user_id' => null,
        ];
    }

    public function forOrganization(Organization $organization): static
    {
        return $this->afterMaking(fn (UnavailablePeriod $period): UnavailablePeriod => $period->forceFill([
            'organization_id' => $organization->getKey(),
        ]));
    }

    public function forSpecialist(Specialist $specialist): static
    {
        return $this->afterMaking(fn (UnavailablePeriod $period): UnavailablePeriod => $period->forceFill([
            'organization_id' => $specialist->organization_id,
            'specialist_id' => $specialist->getKey(),
        ]));
    }
}
