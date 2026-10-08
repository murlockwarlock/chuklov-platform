<?php

namespace App\Modules\Surveys\Application;

use App\Modules\Surveys\Domain\Models\SurveyAttempt;
use App\Modules\Surveys\Domain\Models\SurveyComparison;

final readonly class SurveyComparisonPresentation
{
    public function handle(
        SurveyComparison $comparison,
        ?SurveyAttempt $current,
        ?SurveyAttempt $previous,
        string $locale,
    ): array {
        $metricDefinitions = $this->metricDefinitions($current?->scoring_snapshot['metrics'] ?? [], $locale);
        $configuration = $comparison->comparison_snapshot['configuration'] ?? [];
        $metrics = [];

        foreach ((array) ($comparison->comparison_snapshot['metrics'] ?? []) as $key => $metric) {
            if (! is_array($metric)) {
                continue;
            }

            $label = $metricDefinitions[$key]['label'] ?? (string) $key;
            $direction = $this->direction($metricDefinitions[$key] ?? [], $configuration);
            $row = $this->numericMetric($key, $label, $metric, $direction, $locale)
                ?? $this->stateMetric($key, $label, $metric, $direction, $locale);

            if ($row !== null) {
                $metrics[] = $row;
            }
        }

        return [
            'id' => (int) $comparison->getKey(),
            'status' => (string) $comparison->status,
            'beforeDate' => $previous?->completed_at?->toIso8601String(),
            'afterDate' => $current?->completed_at?->toIso8601String(),
            'metrics' => $metrics,
            'hasData' => $metrics !== [],
            'message' => $this->message((string) $comparison->status, $locale),
            'telegramText' => $this->telegramText($metrics, $locale),
        ];
    }

    private function numericMetric(
        string|int $key,
        string $label,
        array $metric,
        ?string $direction,
        string $locale,
    ): ?array {
        if (! is_numeric($metric['before'] ?? null) || ! is_numeric($metric['after'] ?? null)) {
            return null;
        }

        $before = (float) $metric['before'];
        $after = (float) $metric['after'];
        $change = (float) ($metric['delta'] ?? ($after - $before));

        return [
            'key' => (string) $key,
            'label' => $label,
            'kind' => 'numeric',
            'before' => $this->displayNumber($before),
            'after' => $this->displayNumber($after),
            'change' => $this->displayNumber($change),
            'beforeDisplay' => $this->numberText($before),
            'afterDisplay' => $this->numberText($after),
            'changeDisplay' => $this->signedNumberText($change),
            'trend' => $this->trend($change, $direction),
            'trendLabel' => $this->trendLabel($this->trend($change, $direction), $locale),
        ];
    }

    private function stateMetric(
        string|int $key,
        string $label,
        array $metric,
        ?string $direction,
        string $locale,
    ): ?array {
        $before = $metric['before'] ?? null;
        $after = $metric['after'] ?? null;

        if (! $this->isStateValue($before) || ! $this->isStateValue($after)) {
            return null;
        }

        $trend = $this->stateTrend($before, $after, $direction);

        return [
            'key' => (string) $key,
            'label' => $label,
            'kind' => 'state',
            'before' => $this->stateText($before, $locale),
            'after' => $this->stateText($after, $locale),
            'change' => null,
            'beforeDisplay' => $this->stateText($before, $locale),
            'afterDisplay' => $this->stateText($after, $locale),
            'changeDisplay' => null,
            'trend' => $trend,
            'trendLabel' => $this->trendLabel($trend, $locale),
        ];
    }

    private function direction(array $metric, array $configuration): ?string
    {
        $direction = $metric['direction'] ?? $metric['improvement_direction'] ?? $configuration['direction'] ?? null;

        if (is_string($direction) && in_array($direction, [
            'lower_is_better',
            'higher_is_better',
            'true_is_better',
            'false_is_better',
        ], true)) {
            return $direction;
        }

        return ($configuration['operator'] ?? null) === 'no_decrease' ? 'lower_is_better' : null;
    }

    private function trend(float $change, ?string $direction): ?string
    {
        if ($direction === null || $change === 0.0) {
            return $change === 0.0 ? 'stable' : null;
        }

        return match ($direction) {
            'lower_is_better' => $change < 0 ? 'improved' : 'worsened',
            'higher_is_better' => $change > 0 ? 'improved' : 'worsened',
            default => null,
        };
    }

    private function stateTrend(mixed $before, mixed $after, ?string $direction): ?string
    {
        if ($before === $after) {
            return 'stable';
        }

        return match ($direction) {
            'true_is_better' => $after === true ? 'improved' : 'worsened',
            'false_is_better' => $after === false ? 'improved' : 'worsened',
            default => null,
        };
    }

    private function isStateValue(mixed $value): bool
    {
        return is_bool($value) || is_string($value) && trim($value) !== '';
    }

    private function stateText(bool|string $value, string $locale): string
    {
        if (is_bool($value)) {
            return $value
                ? ($locale === 'ru' ? 'Да' : 'Yes')
                : ($locale === 'ru' ? 'Нет' : 'No');
        }

        return trim($value);
    }

    private function displayNumber(float $value): int|float
    {
        return fmod($value, 1.0) === 0.0 ? (int) $value : $value;
    }

    private function numberText(float $value): string
    {
        return (string) $this->displayNumber($value);
    }

    private function signedNumberText(float $value): string
    {
        $text = $this->numberText($value);

        return $value > 0 ? '+'.$text : $text;
    }

    private function trendLabel(?string $trend, string $locale): ?string
    {
        return match ($trend) {
            'improved' => $locale === 'ru' ? 'улучшение' : 'improved',
            'worsened' => $locale === 'ru' ? 'изменение' : 'changed',
            'stable' => $locale === 'ru' ? 'без изменения' : 'no change',
            default => null,
        };
    }

    private function message(string $status, string $locale): string
    {
        return match ($status) {
            'improved' => $locale === 'ru'
                ? 'По отмеченным показателям есть положительная динамика.'
                : 'The measures you recorded show positive change.',
            'stagnation_detected' => $locale === 'ru'
                ? 'По отмеченным показателям заметной динамики пока нет.'
                : 'There is no clear change in the measures you recorded yet.',
            'changed' => $locale === 'ru'
                ? 'По отмеченным показателям динамика разная.'
                : 'The measures you recorded changed in different ways.',
            default => $locale === 'ru'
                ? 'Эти два замера нельзя надёжно сопоставить.'
                : 'These two checks cannot be reliably compared.',
        };
    }

    private function telegramText(array $metrics, string $locale): ?string
    {
        if ($metrics === []) {
            return null;
        }

        $intro = $locale === 'ru'
            ? 'Вот как изменились отмеченные вами показатели после предыдущего замера:'
            : 'Here is how the measures you recorded changed since the previous check:';
        $lines = [];
        foreach (array_slice($metrics, 0, 8) as $metric) {
            $line = $metric['label'].': '.$metric['beforeDisplay'].' → '.$metric['afterDisplay'];
            if ($metric['changeDisplay'] !== null) {
                $line .= ' ('.($locale === 'ru' ? 'изменение' : 'change').': '.$metric['changeDisplay'].')';
            }
            if ($metric['trendLabel'] !== null) {
                $line .= ' — '.$metric['trendLabel'];
            }
            $lines[] = $line;
        }

        return mb_substr($intro."\n".implode("\n", $lines), 0, 3500);
    }

    private function metricDefinitions(array $metrics, string $locale): array
    {
        $definitions = [];
        foreach ($metrics as $metric) {
            if (! is_array($metric) || ! is_string($metric['key'] ?? null)) {
                continue;
            }

            $label = $metric['label'] ?? $metric['key'];
            if (is_array($label)) {
                $label = $label[$locale] ?? $label['en'] ?? $label['ru'] ?? $metric['key'];
            }

            $definitions[$metric['key']] = [
                'label' => is_string($label) && trim($label) !== '' ? trim($label) : $metric['key'],
                'direction' => $metric['direction'] ?? $metric['improvement_direction'] ?? null,
                'improvement_direction' => $metric['improvement_direction'] ?? null,
            ];
        }

        return $definitions;
    }
}
