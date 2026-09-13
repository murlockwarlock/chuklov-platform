<?php

namespace App\Filament\Support;

final class SurveyDefinitionScoringFormMapper
{
    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function normalize(array $data): array
    {
        $scoring = [];

        $metricResultContent = is_array($data['metric_result_content'] ?? null)
            ? $data['metric_result_content']
            : [];
        $selectedMetricKey = $data['result_metric_key'] ?? null;
        if (is_string($selectedMetricKey) && $selectedMetricKey !== '') {
            $selectedContent = self::selectedMetricContent($data);
            if (is_array($metricResultContent[$selectedMetricKey] ?? null) || array_filter($selectedContent, static fn (mixed $value): bool => $value !== null) !== []) {
                $metricResultContent[$selectedMetricKey] = [
                    ...(is_array($metricResultContent[$selectedMetricKey] ?? null) ? $metricResultContent[$selectedMetricKey] : []),
                    ...$selectedContent,
                ];
            }
        }

        if (is_array($data['answer_scale'] ?? null) && $data['answer_scale'] !== []) {
            $answerScale = [];
            foreach ($data['answer_scale'] as $scale) {
                if (! is_array($scale) || ! is_string($scale['value'] ?? null) || $scale['value'] === '') {
                    continue;
                }
                $answerScale[$scale['value']] = self::number($scale['points'] ?? null);
            }
            $scoring['answer_scale'] = $answerScale;
        }

        $metrics = [];
        foreach (is_array($data['metrics'] ?? null) ? $data['metrics'] : [] as $metric) {
            if (! is_array($metric)) {
                continue;
            }

            $metricKey = $metric['key'] ?? null;
            if (is_string($metricKey) && is_array($metricResultContent[$metricKey] ?? null)) {
                $metric = [...$metric, ...$metricResultContent[$metricKey]];
            }

            $metricData = [
                'key' => $metric['key'] ?? null,
                'label' => self::localized($metric['label'] ?? null, $metric['label_en'] ?? null),
            ];
            foreach (['max_value', 'normalization', 'question_keys', 'attention_reason', 'observation', 'road_map'] as $key) {
                if (! array_key_exists($key, $metric)) {
                    continue;
                }
                if ($key === 'question_keys') {
                    if (is_array($metric[$key]) && $metric[$key] !== []) {
                        $metricData[$key] = array_values($metric[$key]);
                    }

                    continue;
                }
                if (in_array($key, ['attention_reason', 'observation', 'road_map'], true)) {
                    $value = self::localized($metric[$key] ?? null, $metric[$key.'_en'] ?? null);
                    if ($value !== null || (is_array($metricResultContent[$metricKey] ?? null) && array_key_exists($key, $metricResultContent[$metricKey]))) {
                        $metricData[$key] = $value;
                    }

                    continue;
                }
                if ($key === 'max_value') {
                    if ($metric[$key] !== null && $metric[$key] !== '') {
                        $metricData[$key] = self::number($metric[$key]);
                    }

                    continue;
                }
                if ($metric[$key] !== null && $metric[$key] !== '') {
                    $metricData[$key] = $metric[$key];
                }
            }
            $metrics[] = $metricData;
        }

        $rules = [];
        foreach (is_array($data['rules'] ?? null) ? $data['rules'] : [] as $rule) {
            if (! is_array($rule)) {
                continue;
            }
            $points = [];
            foreach (is_array($rule['points'] ?? null) ? $rule['points'] : [] as $point) {
                if (is_array($point) && is_string($point['value'] ?? null) && $point['value'] !== '') {
                    $points[$point['value']] = self::number($point['points'] ?? null);
                }
            }
            $ruleData = [
                'question_key' => $rule['question_key'] ?? null,
                'metric_key' => $rule['metric_key'] ?? null,
                'operator' => $rule['operator'] ?? null,
            ];
            if ($points !== []) {
                $ruleData['points'] = $points;
            }
            if (array_key_exists('multiplier', $rule) && $rule['multiplier'] !== null && $rule['multiplier'] !== '') {
                $ruleData['multiplier'] = self::number($rule['multiplier']);
            }
            $rules[] = $ruleData;
        }

        $thresholds = [];
        foreach (is_array($data['thresholds'] ?? null) ? $data['thresholds'] : [] as $threshold) {
            if (! is_array($threshold)) {
                continue;
            }
            $thresholdData = [
                'metric_key' => $threshold['metric_key'] ?? null,
            ];
            foreach (['min', 'max'] as $bound) {
                if (array_key_exists($bound, $threshold) && $threshold[$bound] !== null && $threshold[$bound] !== '') {
                    $thresholdData[$bound] = self::number($threshold[$bound]);
                }
            }
            $thresholdData['tag'] = $threshold['tag'] ?? null;
            $thresholdData['label'] = self::localized($threshold['label'] ?? null, $threshold['label_en'] ?? null);
            $thresholds[] = $thresholdData;
        }

        $scoring['metrics'] = $metrics;
        $scoring['rules'] = $rules;
        $scoring['thresholds'] = $thresholds;
        $comparisonKeys = is_array($data['comparison_metric_keys'] ?? null)
            ? array_values($data['comparison_metric_keys'])
            : [];
        $scoring['comparison'] = $comparisonKeys === []
            ? null
            : array_filter([
                'operator' => $data['comparison_operator'] ?? 'no_decrease',
                'metric_keys' => $comparisonKeys,
                'basis' => $data['comparison_basis'] ?? null,
            ], static fn (mixed $value): bool => $value !== null && $value !== '');

        foreach ([
            'summary' => ['summary', 'summary_en'],
        ] as $canonicalKey => [$formKey, $formEnglishKey]) {
            if (array_key_exists($formKey, $data) && filled($data[$formKey])) {
                $scoring[$canonicalKey] = self::localized($data[$formKey], $data[$formEnglishKey] ?? null);
            }
        }

        foreach (['safe_steps', 'specialist_questions'] as $canonicalKey) {
            $textKey = $canonicalKey.'_text';
            $textEnglishKey = $textKey.'_en';
            if (array_key_exists($textKey, $data) || array_key_exists($textEnglishKey, $data)) {
                $items = self::localizedList(
                    self::splitLines($data[$textKey] ?? null),
                    self::splitLines($data[$textEnglishKey] ?? null),
                );
                $scoring[$canonicalKey] = $items;

                continue;
            }
            if (! array_key_exists($canonicalKey, $data) || ! is_array($data[$canonicalKey]) || $data[$canonicalKey] === []) {
                continue;
            }
            $items = [];
            foreach ($data[$canonicalKey] as $item) {
                if (is_array($item) && filled($item['text'] ?? null)) {
                    $items[] = self::localized($item['text'], $item['text_en'] ?? null);
                }
            }
            $scoring[$canonicalKey] = $items;
        }

        return $scoring;
    }

    /**
     * @param  array<string, mixed>  $scoring
     * @return array<string, mixed>
     */
    public static function denormalize(array $scoring): array
    {
        $data = [];

        if (is_array($scoring['answer_scale'] ?? null)) {
            $data['answer_scale'] = [];
            foreach ($scoring['answer_scale'] as $value => $points) {
                $data['answer_scale'][] = [
                    'value' => (string) $value,
                    'points' => $points,
                ];
            }
        }

        $data['metrics'] = [];
        $data['metric_result_content'] = [];
        foreach (is_array($scoring['metrics'] ?? null) ? $scoring['metrics'] : [] as $metric) {
            if (! is_array($metric)) {
                continue;
            }
            [$label, $labelEn] = self::denormalizeText($metric['label'] ?? null);
            $formMetric = [
                'key' => $metric['key'] ?? null,
                'label' => $label,
                'label_en' => $labelEn,
            ];
            foreach (['max_value', 'normalization', 'question_keys'] as $key) {
                if (array_key_exists($key, $metric)) {
                    $formMetric[$key] = $metric[$key];
                }
            }
            foreach (['attention_reason', 'observation', 'road_map'] as $key) {
                if (array_key_exists($key, $metric)) {
                    [$text, $textEn] = self::denormalizeText($metric[$key]);
                    $formMetric[$key] = $text;
                    $formMetric[$key.'_en'] = $textEn;
                    if (is_string($metric['key'] ?? null)) {
                        $data['metric_result_content'][$metric['key']][$key] = $text;
                        $data['metric_result_content'][$metric['key']][$key.'_en'] = $textEn;
                    }
                }
            }
            $data['metrics'][] = $formMetric;
        }

        $firstMetricKey = $data['metrics'][0]['key'] ?? null;
        $data['result_metric_key'] = is_string($firstMetricKey) ? $firstMetricKey : null;
        if (is_string($firstMetricKey)) {
            foreach (['attention_reason', 'observation', 'road_map'] as $key) {
                $data['result_'.$key] = $data['metric_result_content'][$firstMetricKey][$key] ?? null;
                $data['result_'.$key.'_en'] = $data['metric_result_content'][$firstMetricKey][$key.'_en'] ?? null;
            }
        }

        $data['rules'] = [];
        foreach (is_array($scoring['rules'] ?? null) ? $scoring['rules'] : [] as $rule) {
            if (! is_array($rule)) {
                continue;
            }
            $points = [];
            foreach (is_array($rule['points'] ?? null) ? $rule['points'] : [] as $value => $pointsValue) {
                $points[] = ['value' => (string) $value, 'points' => $pointsValue];
            }
            $data['rules'][] = [
                'question_key' => $rule['question_key'] ?? null,
                'metric_key' => $rule['metric_key'] ?? null,
                'operator' => $rule['operator'] ?? null,
                'points' => $points,
                'multiplier' => $rule['multiplier'] ?? null,
            ];
        }

        $data['thresholds'] = [];
        foreach (is_array($scoring['thresholds'] ?? null) ? $scoring['thresholds'] : [] as $threshold) {
            if (! is_array($threshold)) {
                continue;
            }
            [$label, $labelEn] = self::denormalizeText($threshold['label'] ?? null);
            $data['thresholds'][] = [
                'metric_key' => $threshold['metric_key'] ?? null,
                'min' => $threshold['min'] ?? null,
                'max' => $threshold['max'] ?? null,
                'tag' => $threshold['tag'] ?? null,
                'label' => $label,
                'label_en' => $labelEn,
            ];
        }

        $comparison = is_array($scoring['comparison'] ?? null) ? $scoring['comparison'] : [];
        $data['comparison_operator'] = $comparison['operator'] ?? null;
        $data['comparison_metric_keys'] = is_array($comparison['metric_keys'] ?? null)
            ? array_values($comparison['metric_keys'])
            : [];
        $data['comparison_basis'] = $comparison['basis'] ?? null;

        if (array_key_exists('summary', $scoring)) {
            [$summary, $summaryEn] = self::denormalizeText($scoring['summary']);
            $data['summary'] = $summary;
            $data['summary_en'] = $summaryEn;
        }

        foreach (['safe_steps', 'specialist_questions'] as $key) {
            if (! array_key_exists($key, $scoring)) {
                continue;
            }
            $data[$key] = [];
            $ruItems = [];
            $enItems = [];
            foreach (is_array($scoring[$key]) ? $scoring[$key] : [] as $item) {
                [$text, $textEn] = self::denormalizeText($item);
                $data[$key][] = ['text' => $text, 'text_en' => $textEn];
                $ruItems[] = $text ?? '';
                $enItems[] = $textEn ?? '';
            }
            $data[$key.'_text'] = implode(PHP_EOL, $ruItems);
            $data[$key.'_text_en'] = implode(PHP_EOL, $enItems);
        }

        return $data;
    }

    /** @return array<string, string|null> */
    private static function selectedMetricContent(array $data): array
    {
        $content = [];
        foreach (['attention_reason', 'observation', 'road_map'] as $key) {
            $content[$key] = self::blankToNull($data['result_'.$key] ?? null);
            $content[$key.'_en'] = self::blankToNull($data['result_'.$key.'_en'] ?? null);
        }

        return $content;
    }

    private static function blankToNull(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return $value;
    }

    /** @return list<string> */
    private static function splitLines(mixed $value): array
    {
        if (! is_string($value)) {
            return [];
        }

        return array_map(static fn (string $line): string => trim($line), preg_split('/\R/u', $value) ?: []);
    }

    /** @param list<string> $ruItems  @param list<string> $enItems  @return list<string|array{ru: string, en: string}> */
    private static function localizedList(array $ruItems, array $enItems): array
    {
        $items = [];
        $count = max(count($ruItems), count($enItems));
        for ($index = 0; $index < $count; $index++) {
            $ru = self::blankToNull($ruItems[$index] ?? null);
            $en = self::blankToNull($enItems[$index] ?? null);
            if ($ru === null && $en === null) {
                continue;
            }
            $items[] = self::localized($ru, $en);
        }

        return $items;
    }

    /** @return string|array{ru?: string, en?: string}|null */
    private static function localized(mixed $ru, mixed $en): string|array|null
    {
        if ($ru === null && ($en === null || trim((string) $en) === '')) {
            return null;
        }
        if (! is_string($en) || trim($en) === '') {
            return (string) $ru;
        }
        if ($ru === null || trim($ru) === '') {
            return ['en' => $en];
        }

        return ['ru' => $ru, 'en' => $en];
    }

    private static function number(mixed $value): int|float|null
    {
        if (! is_numeric($value)) {
            return null;
        }

        $value = trim((string) $value);

        return preg_match('/^[+-]?\d+$/', $value) === 1 ? (int) $value : (float) $value;
    }

    /** @return array{0: string|null, 1: string|null} */
    private static function denormalizeText(mixed $value): array
    {
        if ($value === null) {
            return [null, null];
        }
        if (! is_array($value)) {
            return [(string) $value, null];
        }

        return [
            array_key_exists('ru', $value) && $value['ru'] !== null ? (string) $value['ru'] : null,
            array_key_exists('en', $value) && $value['en'] !== null ? (string) $value['en'] : null,
        ];
    }
}
