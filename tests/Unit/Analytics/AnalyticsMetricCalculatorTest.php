<?php

namespace Tests\Unit\Analytics;

use App\Modules\Analytics\Application\AnalyticsMetricCalculator;
use App\Modules\Analytics\Domain\Enums\AnalyticsMetricCalculation;
use App\Modules\Analytics\Domain\Enums\AnalyticsMetricNullPolicy;
use App\Modules\Analytics\Domain\Enums\AnalyticsMetricZeroPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AnalyticsMetricCalculatorTest extends TestCase
{
    #[DataProvider('calculations')]
    public function test_typed_calculations_are_safe(
        AnalyticsMetricCalculation $calculation,
        ?float $a,
        ?float $b,
        ?float $expected,
    ): void {
        $actual = (new AnalyticsMetricCalculator)->calculate(
            $calculation,
            $a,
            $b,
            AnalyticsMetricNullPolicy::DoNotCalculate,
            AnalyticsMetricZeroPolicy::DoNotCalculate,
        );

        if ($expected === null) {
            self::assertNull($actual);

            return;
        }

        self::assertSame($expected, $actual);
    }

    public function test_null_and_zero_policies_are_explicit(): void
    {
        $calculator = new AnalyticsMetricCalculator;

        self::assertSame(4.0, $calculator->calculate(
            AnalyticsMetricCalculation::Difference,
            4.0,
            null,
            AnalyticsMetricNullPolicy::TreatAsZero,
            AnalyticsMetricZeroPolicy::DoNotCalculate,
        ));
        self::assertSame(0.0, $calculator->calculate(
            AnalyticsMetricCalculation::RatioPercent,
            20.0,
            0.0,
            AnalyticsMetricNullPolicy::DoNotCalculate,
            AnalyticsMetricZeroPolicy::ShowZero,
        ));
    }

    /** @return array<string, array{0: AnalyticsMetricCalculation, 1: ?float, 2: ?float, 3: ?float}> */
    public static function calculations(): array
    {
        return [
            'pain reduction' => [AnalyticsMetricCalculation::ReductionPercent, 8.0, 4.0, 50.0],
            'pain before zero' => [AnalyticsMetricCalculation::ReductionPercent, 0.0, 0.0, null],
            'pain after missing' => [AnalyticsMetricCalculation::ReductionPercent, 8.0, null, null],
            'ltv period change' => [AnalyticsMetricCalculation::ChangePercent, 120.0, 100.0, 20.0],
            'ltv previous zero' => [AnalyticsMetricCalculation::ChangePercent, 120.0, 0.0, null],
            'custom difference' => [AnalyticsMetricCalculation::Difference, 8.0, 3.0, 5.0],
            'custom ratio percent' => [AnalyticsMetricCalculation::RatioPercent, 25.0, 100.0, 25.0],
            'current versus target' => [AnalyticsMetricCalculation::ChangePercent, 120.0, 100.0, 20.0],
        ];
    }
}
