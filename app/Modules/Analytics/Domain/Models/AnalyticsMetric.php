<?php

namespace App\Modules\Analytics\Domain\Models;

use App\Modules\Analytics\Domain\Enums\AnalyticsMetricAggregation;
use App\Modules\Analytics\Domain\Enums\AnalyticsMetricCalculation;
use App\Modules\Analytics\Domain\Enums\AnalyticsMetricComparison;
use App\Modules\Analytics\Domain\Enums\AnalyticsMetricNullPolicy;
use App\Modules\Analytics\Domain\Enums\AnalyticsMetricUnit;
use App\Modules\Analytics\Domain\Enums\AnalyticsMetricZeroPolicy;
use App\Modules\Organizations\Domain\Models\Organization;
use Database\Factories\AnalyticsMetricFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property bool $active
 * @property int $organization_id
 * @property AnalyticsMetricUnit $output_unit
 * @property int $decimal_precision
 * @property AnalyticsMetricAggregation $aggregation
 * @property AnalyticsMetricCalculation $calculation
 * @property AnalyticsMetricComparison $comparison
 * @property int|null $comparison_metric_id
 * @property string|null $target_value
 * @property AnalyticsMetricNullPolicy $null_policy
 * @property AnalyticsMetricZeroPolicy $zero_policy
 * @property int $sort_order
 * @property int $version
 */
#[Fillable([
    'organization_id',
    'stable_key',
    'name',
    'description',
    'active',
    'output_unit',
    'decimal_precision',
    'source_a_key',
    'source_b_key',
    'aggregation',
    'calculation',
    'comparison',
    'comparison_metric_id',
    'target_value',
    'null_policy',
    'zero_policy',
    'sort_order',
    'version',
])]
class AnalyticsMetric extends Model
{
    protected $table = 'analytics_metric_configurations';

    /** @use HasFactory<AnalyticsMetricFactory> */
    use HasFactory;

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<self, $this> */
    public function comparisonMetric(): BelongsTo
    {
        return $this->belongsTo(self::class, 'comparison_metric_id');
    }

    protected static function newFactory(): AnalyticsMetricFactory
    {
        return AnalyticsMetricFactory::new();
    }

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'output_unit' => AnalyticsMetricUnit::class,
            'decimal_precision' => 'integer',
            'aggregation' => AnalyticsMetricAggregation::class,
            'calculation' => AnalyticsMetricCalculation::class,
            'comparison' => AnalyticsMetricComparison::class,
            'comparison_metric_id' => 'integer',
            'target_value' => 'decimal:8',
            'null_policy' => AnalyticsMetricNullPolicy::class,
            'zero_policy' => AnalyticsMetricZeroPolicy::class,
            'sort_order' => 'integer',
            'version' => 'integer',
        ];
    }
}
