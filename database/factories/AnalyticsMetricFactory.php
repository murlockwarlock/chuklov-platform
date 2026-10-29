<?php

namespace Database\Factories;

use App\Modules\Analytics\Domain\Enums\AnalyticsMetricAggregation;
use App\Modules\Analytics\Domain\Enums\AnalyticsMetricCalculation;
use App\Modules\Analytics\Domain\Enums\AnalyticsMetricComparison;
use App\Modules\Analytics\Domain\Enums\AnalyticsMetricNullPolicy;
use App\Modules\Analytics\Domain\Enums\AnalyticsMetricUnit;
use App\Modules\Analytics\Domain\Enums\AnalyticsMetricZeroPolicy;
use App\Modules\Analytics\Domain\Models\AnalyticsMetric;
use App\Modules\Organizations\Domain\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<AnalyticsMetric> */
class AnalyticsMetricFactory extends Factory
{
    protected $model = AnalyticsMetric::class;

    public function definition(): array
    {
        return [
            'stable_key' => 'custom_'.fake()->unique()->bothify('metric_####'),
            'name' => fake()->words(3, true),
            'description' => null,
            'active' => true,
            'output_unit' => AnalyticsMetricUnit::Number->value,
            'decimal_precision' => 2,
            'source_a_key' => 'acquisition.new_clients',
            'source_b_key' => null,
            'aggregation' => AnalyticsMetricAggregation::Count->value,
            'calculation' => AnalyticsMetricCalculation::Value->value,
            'comparison' => AnalyticsMetricComparison::None->value,
            'comparison_metric_id' => null,
            'target_value' => null,
            'null_policy' => AnalyticsMetricNullPolicy::DoNotCalculate->value,
            'zero_policy' => AnalyticsMetricZeroPolicy::DoNotCalculate->value,
            'sort_order' => 100,
            'version' => 1,
        ];
    }

    public function forOrganization(Organization $organization): static
    {
        return $this->state(['organization_id' => $organization->getKey()]);
    }
}
