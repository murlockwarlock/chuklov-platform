<?php

namespace Tests\Unit;

use App\Modules\Surveys\Application\SurveyComparisonPresentation;
use App\Modules\Surveys\Domain\Models\SurveyAttempt;
use App\Modules\Surveys\Domain\Models\SurveyComparison;
use Carbon\CarbonImmutable;
use Tests\TestCase;

final class SurveyComparisonPresentationTest extends TestCase
{
    public function test_numeric_metrics_use_absolute_change_and_explicit_direction_without_percentage(): void
    {
        $current = $this->attempt([
            ['key' => 'pain', 'label' => ['ru' => 'Боль', 'en' => 'Pain'], 'direction' => 'lower_is_better'],
        ]);
        $previous = $this->attempt([
            ['key' => 'pain', 'label' => ['ru' => 'Боль', 'en' => 'Pain'], 'direction' => 'lower_is_better'],
        ]);
        $comparison = $this->comparison([
            'configuration' => ['operator' => 'numeric_value'],
            'metrics' => [
                'pain' => ['before' => 10, 'after' => 7, 'delta' => -3],
            ],
        ]);

        $projection = app(SurveyComparisonPresentation::class)->handle($comparison, $current, $previous, 'ru');

        self::assertTrue($projection['hasData']);
        self::assertSame(-3, $projection['metrics'][0]['change']);
        self::assertSame('-3', $projection['metrics'][0]['changeDisplay']);
        self::assertSame('improved', $projection['metrics'][0]['trend']);
        self::assertStringNotContainsString('%', (string) $projection['telegramText']);
        self::assertStringContainsString('Вот как изменились отмеченные вами показатели после предыдущего замера:', $projection['telegramText']);
    }

    public function test_boolean_state_metrics_are_presented_without_inventing_numeric_progress(): void
    {
        $current = $this->attempt([
            ['key' => 'swelling', 'label' => ['ru' => 'Отёк', 'en' => 'Swelling'], 'direction' => 'false_is_better'],
        ]);
        $previous = $this->attempt([
            ['key' => 'swelling', 'label' => ['ru' => 'Отёк', 'en' => 'Swelling'], 'direction' => 'false_is_better'],
        ]);
        $comparison = $this->comparison([
            'metrics' => [
                'swelling' => ['before' => true, 'after' => false],
            ],
        ]);

        $projection = app(SurveyComparisonPresentation::class)->handle($comparison, $current, $previous, 'en');

        self::assertSame('Yes', $projection['metrics'][0]['beforeDisplay']);
        self::assertSame('No', $projection['metrics'][0]['afterDisplay']);
        self::assertNull($projection['metrics'][0]['change']);
        self::assertSame('improved', $projection['metrics'][0]['trend']);
        self::assertStringContainsString('Here is how the measures you recorded changed since the previous check:', $projection['telegramText']);
    }

    public function test_incompatible_comparison_has_no_progress_table(): void
    {
        $comparison = $this->comparison([
            'metrics' => [],
        ]);
        $comparison->status = 'not_comparable';

        $projection = app(SurveyComparisonPresentation::class)->handle($comparison, null, null, 'ru');

        self::assertFalse($projection['hasData']);
        self::assertSame([], $projection['metrics']);
        self::assertNull($projection['telegramText']);
    }

    /** @param list<array<string, mixed>> $metrics */
    private function attempt(array $metrics): SurveyAttempt
    {
        $attempt = new SurveyAttempt;
        $attempt->forceFill([
            'scoring_snapshot' => ['metrics' => $metrics],
            'completed_at' => CarbonImmutable::parse('2026-09-23 12:00:00', 'UTC'),
        ]);

        return $attempt;
    }

    /** @param array<string, mixed> $snapshot */
    private function comparison(array $snapshot): SurveyComparison
    {
        $comparison = new SurveyComparison;
        $comparison->forceFill([
            'id' => 1,
            'status' => 'improved',
            'comparison_snapshot' => $snapshot,
        ]);

        return $comparison;
    }
}
