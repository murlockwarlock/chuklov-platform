<?php

namespace App\Modules\Analytics\Application;

use App\Modules\Analytics\Domain\Enums\AnalyticsMetricAggregation;
use App\Modules\Analytics\Domain\Enums\AnalyticsMetricCalculation;
use App\Modules\Analytics\Domain\Enums\AnalyticsMetricComparison;
use App\Modules\Analytics\Domain\Enums\AnalyticsMetricNullPolicy;
use App\Modules\Analytics\Domain\Enums\AnalyticsMetricUnit;
use App\Modules\Analytics\Domain\Enums\AnalyticsMetricZeroPolicy;
use App\Modules\Analytics\Domain\Models\AnalyticsMetric;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

final readonly class AnalyticsMetricDefinitionValidator
{
    /**
     * @param  array<string, mixed>  $data
     * @param  Collection<int, AnalyticsMetric>  $knownMetrics
     * @return array<string, mixed>
     */
    public function validate(array $data, ?AnalyticsMetric $existing, Collection $knownMetrics): array
    {
        $errors = [];
        $name = is_string($data['name'] ?? null) ? trim($data['name']) : '';
        $description = is_string($data['description'] ?? null) ? trim($data['description']) : null;
        $sourceA = is_string($data['source_a_key'] ?? null) ? trim($data['source_a_key']) : '';
        $sourceB = is_string($data['source_b_key'] ?? null) && trim($data['source_b_key']) !== ''
            ? trim($data['source_b_key'])
            : null;
        $calculation = is_string($data['calculation'] ?? null)
            ? AnalyticsMetricCalculation::tryFrom($data['calculation'])
            : null;
        $comparison = is_string($data['comparison'] ?? null)
            ? AnalyticsMetricComparison::tryFrom($data['comparison'])
            : null;
        $aggregation = is_string($data['aggregation'] ?? null)
            ? AnalyticsMetricAggregation::tryFrom($data['aggregation'])
            : null;
        $unit = is_string($data['output_unit'] ?? null)
            ? AnalyticsMetricUnit::tryFrom($data['output_unit'])
            : null;
        $nullPolicy = is_string($data['null_policy'] ?? null)
            ? AnalyticsMetricNullPolicy::tryFrom($data['null_policy'])
            : null;
        $zeroPolicy = is_string($data['zero_policy'] ?? null)
            ? AnalyticsMetricZeroPolicy::tryFrom($data['zero_policy'])
            : null;
        $precision = filter_var($data['decimal_precision'] ?? null, FILTER_VALIDATE_INT);
        $sortOrder = filter_var($data['sort_order'] ?? 0, FILTER_VALIDATE_INT);
        $comparisonMetricId = $this->positiveId($data['comparison_metric_id'] ?? null);

        if ($name === '' || mb_strlen($name) > 160) {
            $errors['name'] = 'Название метрики должно содержать от 1 до 160 символов.';
        }
        if ($description !== null && mb_strlen($description) > 500) {
            $errors['description'] = 'Описание метрики не должно превышать 500 символов.';
        }
        if (! array_key_exists($sourceA, AnalyticsMetricSourceCatalog::definitions())) {
            $errors['source_a_key'] = 'Выберите доступный источник данных.';
        }
        if ($sourceB !== null && ! array_key_exists($sourceB, AnalyticsMetricSourceCatalog::definitions())) {
            $errors['source_b_key'] = 'Выберите доступный второй источник данных.';
        }
        if ($calculation === null) {
            $errors['calculation'] = 'Выберите операцию расчёта.';
        }
        if ($comparison === null) {
            $errors['comparison'] = 'Выберите основу сравнения.';
        }
        if ($aggregation === null || ! AnalyticsMetricSourceCatalog::aggregationAllowed($sourceA, $aggregation)) {
            $errors['aggregation'] = 'Выберите допустимое агрегирование для источника.';
        }
        if ($unit === null) {
            $errors['output_unit'] = 'Выберите единицу результата.';
        }
        if ($nullPolicy === null) {
            $errors['null_policy'] = 'Выберите поведение при отсутствии данных.';
        }
        if ($zeroPolicy === null) {
            $errors['zero_policy'] = 'Выберите поведение при делении на 0.';
        }
        if (! is_int($precision) || $precision < 0 || $precision > 3) {
            $errors['decimal_precision'] = 'Округление должно быть от 0 до 3 знаков.';
        }
        if (! is_int($sortOrder) || $sortOrder < 0 || $sortOrder > 100000) {
            $errors['sort_order'] = 'Порядок должен быть неотрицательным числом.';
        }

        if ($calculation !== null && $comparison !== null) {
            if ($calculation === AnalyticsMetricCalculation::Value && $comparison !== AnalyticsMetricComparison::None) {
                $errors['comparison'] = 'Для показа значения без операции сравнение не используется.';
            }
            if ($comparison === AnalyticsMetricComparison::None
                && ! AnalyticsMetricSourceCatalog::compatible($sourceA, $sourceB, $calculation)) {
                $errors['source_b_key'] = 'Выберите второй источник того же типа данных.';
            }
            if ($comparison !== AnalyticsMetricComparison::None && $sourceB !== null) {
                $errors['source_b_key'] = 'При выбранном сравнении второй источник не нужен.';
            }
            if ($comparison !== AnalyticsMetricComparison::None && ! $calculation->requiresSecondValue()) {
                $errors['calculation'] = 'Выберите операцию, которая использует основу сравнения.';
            }
        }

        if ($unit !== null && $calculation !== null) {
            $expectedUnit = AnalyticsMetricSourceCatalog::outputUnit($sourceA, $calculation);
            if ($expectedUnit !== null && $unit !== $expectedUnit) {
                $errors['output_unit'] = 'Единица результата не соответствует выбранной операции.';
            }
        }

        if ($comparison === AnalyticsMetricComparison::Metric) {
            $comparisonMetric = $comparisonMetricId === null
                ? null
                : $knownMetrics->first(fn (AnalyticsMetric $metric): bool => (int) $metric->getKey() === $comparisonMetricId);
            if (! $comparisonMetric instanceof AnalyticsMetric) {
                $errors['comparison_metric_id'] = 'Выберите доступную активную метрику для сравнения.';
            } elseif ($existing !== null && (int) $comparisonMetric->getKey() === (int) $existing->getKey()) {
                $errors['comparison_metric_id'] = 'Метрику нельзя сравнивать с самой собой.';
            } elseif (AnalyticsMetricUnit::tryFrom((string) $comparisonMetric->getRawOriginal('output_unit'))
                !== AnalyticsMetricSourceCatalog::outputUnit($sourceA, AnalyticsMetricCalculation::Value)) {
                $errors['comparison_metric_id'] = 'Сравниваемые метрики должны иметь одну единицу.';
            } elseif (AnalyticsMetricSourceCatalog::kind($comparisonMetric->source_a_key)
                !== AnalyticsMetricSourceCatalog::kind($sourceA)) {
                $errors['comparison_metric_id'] = 'Сравниваемые метрики должны иметь совместимый тип данных.';
            }
        } elseif ($comparisonMetricId !== null) {
            $errors['comparison_metric_id'] = 'Выберите «Другая выбранная метрика» или очистите сравнение.';
        }

        $targetValue = $this->normalizeTarget($data['target_value'] ?? null);
        if ($comparison === AnalyticsMetricComparison::Target && $targetValue === null) {
            $errors['target_value'] = 'Укажите целевое значение числом.';
        }
        if ($comparison !== AnalyticsMetricComparison::Target && $targetValue !== null) {
            $errors['target_value'] = 'Целевое значение используется только для сравнения с целью.';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        if ($aggregation === null || $calculation === null || $comparison === null || $unit === null
            || $nullPolicy === null || $zeroPolicy === null || ! is_int($precision) || ! is_int($sortOrder)) {
            throw ValidationException::withMessages(['metrics' => 'Проверьте настройки вычисляемых метрик.']);
        }

        return [
            'name' => $name,
            'description' => $description === '' ? null : $description,
            'active' => $this->boolean($data['active'] ?? true),
            'output_unit' => $unit->value,
            'decimal_precision' => (int) $precision,
            'source_a_key' => $sourceA,
            'source_b_key' => $sourceB,
            'aggregation' => $aggregation->value,
            'calculation' => $calculation->value,
            'comparison' => $comparison->value,
            'comparison_metric_id' => $comparisonMetricId,
            'target_value' => $targetValue,
            'null_policy' => $nullPolicy->value,
            'zero_policy' => $zeroPolicy->value,
            'sort_order' => (int) $sortOrder,
        ];
    }

    private function positiveId(mixed $value): ?int
    {
        $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        return $id === false ? null : (int) $id;
    }

    private function boolean(mixed $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? false;
    }

    private function normalizeTarget(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_int($value) || is_float($value)) {
            $value = (string) $value;
        }
        if (! is_string($value)) {
            return null;
        }

        $value = trim(str_replace(',', '.', $value));

        return preg_match('/^-?(?:0|[1-9][0-9]{0,15})(?:\.[0-9]{1,8})?$/D', $value) === 1
            ? $value
            : null;
    }
}
