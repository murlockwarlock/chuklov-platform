<?php

namespace Database\Factories;

use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Tracker\Domain\Models\TrackerPlan;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<TrackerPlan> */
class TrackerPlanFactory extends Factory
{
    protected $model = TrackerPlan::class;

    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'name' => 'Тариф '.$this->faker->unique()->numberBetween(1, 999),
            'is_active' => true,
            'is_visible' => true,
            'current_version_id' => null,
            'archived_at' => null,
        ];
    }
}
