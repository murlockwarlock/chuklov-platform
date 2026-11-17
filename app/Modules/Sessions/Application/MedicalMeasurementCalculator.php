<?php

namespace App\Modules\Sessions\Application;

use Illuminate\Validation\ValidationException;

final class MedicalMeasurementCalculator
{
    /** @var list<string> */
    public const VITAL_KEYS = ['sys', 'dia', 'pulse', 'pi', 'spo2'];

    /** @var list<string> */
    public const ANTHROPOMETRY_KEYS = [
        'height_cm',
        'weight_kg',
        'impedance',
        'waist_cm',
        'hips_cm',
        'chest_cm',
        'right_thigh_cm',
        'left_thigh_cm',
        'right_shoulder_cm',
        'left_shoulder_cm',
    ];

    /** @param array<string, mixed>|null $measurements */
    public function normalizeSession(?array $measurements): ?array
    {
        if ($measurements === null) {
            return null;
        }

        $afterEnabled = filter_var($measurements['after_enabled'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $before = $this->normalizeVitals($measurements['before']['vitals'] ?? []);
        $after = $afterEnabled
            ? $this->normalizeVitals($measurements['after']['vitals'] ?? [])
            : $this->emptyVitals();

        if ($before === $this->emptyVitals() && $after === $this->emptyVitals() && ! $afterEnabled) {
            return null;
        }

        return [
            'before' => ['vitals' => $before],
            'after_enabled' => $afterEnabled,
            'after' => ['vitals' => $after],
        ];
    }

    /** @param array<string, mixed> $phase */
    public function normalizeVitals(array $phase): array
    {
        $values = [];
        foreach (self::VITAL_KEYS as $key) {
            $values[$key] = $this->number($phase[$key] ?? null, $key);
        }

        if ($values['pi'] !== null && ($values['pi'] < 0.02 || $values['pi'] > 20)) {
            throw ValidationException::withMessages([
                'measurements' => 'Индекс перфузии должен быть от 0,02 до 20.',
            ]);
        }
        if ($values['spo2'] !== null && ($values['spo2'] < 0 || $values['spo2'] > 100)) {
            throw ValidationException::withMessages([
                'measurements' => 'Сатурация должна быть от 0 до 100%.',
            ]);
        }
        if ($values['pulse'] !== null && ($values['pulse'] < 0 || $values['pulse'] > 300)) {
            throw ValidationException::withMessages([
                'measurements' => 'Пульс должен быть от 0 до 300 уд/мин.',
            ]);
        }
        foreach (['sys', 'dia'] as $key) {
            if ($values[$key] !== null && ($values[$key] < 0 || $values[$key] > 400)) {
                throw ValidationException::withMessages([
                    'measurements' => 'Значения давления должны быть от 0 до 400 мм рт. ст.',
                ]);
            }
        }

        if ($values['sys'] !== null && $values['dia'] !== null) {
            $pd = $values['sys'] - $values['dia'];
            $values['pd'] = $pd;
            $values['map'] = (int) round($values['dia'] + $pd / 3);
        } else {
            $values['pd'] = null;
            $values['map'] = null;
        }

        return $values;
    }

    /** @param array<string, mixed> $values */
    public function normalizeAnthropometry(array $values): array
    {
        $normalized = [];
        foreach (self::ANTHROPOMETRY_KEYS as $key) {
            $value = $values[$key] ?? null;
            if ($value === null || $value === '') {
                $normalized[$key] = null;
                continue;
            }
            if (! is_numeric($value)) {
                throw ValidationException::withMessages([
                    $key => 'Введите числовое значение.',
                ]);
            }

            $number = round((float) $value, 2);
            $max = $key === 'impedance' ? 100000 : ($key === 'weight_kg' ? 500 : 500);
            $min = in_array($key, ['height_cm', 'weight_kg'], true) ? 0.1 : 0;
            if ($number < $min || $number > $max) {
                throw ValidationException::withMessages([
                    $key => 'Проверьте значение и единицу измерения.',
                ]);
            }

            $normalized[$key] = $number;
        }

        $height = $normalized['height_cm'];
        $weight = $normalized['weight_kg'];
        $normalized['bmi'] = $height !== null && $weight !== null
            ? round($weight / (($height / 100) ** 2), 1)
            : null;

        return $normalized;
    }

    /** @param array<string, mixed> $payload */
    public function evaluate(array $payload, array $thresholds): array
    {
        $phases = ['before', 'after'];
        $result = [];
        foreach ($phases as $phase) {
            $vitals = is_array($payload[$phase]['vitals'] ?? null) ? $payload[$phase]['vitals'] : [];
            $phaseResult = [];
            foreach (self::VITAL_KEYS as $key) {
                $value = $vitals[$key] ?? null;
                $phaseResult[$key] = [
                    'value' => is_numeric($value) ? (float) $value : null,
                    'state' => $this->state($value, is_array($thresholds[$key] ?? null) ? $thresholds[$key] : null),
                ];
            }
            $result[$phase] = $phaseResult;
        }

        return $result;
    }

    /** @return array<string, int|float|null> */
    private function emptyVitals(): array
    {
        return [
            'sys' => null,
            'dia' => null,
            'pulse' => null,
            'pi' => null,
            'spo2' => null,
            'pd' => null,
            'map' => null,
        ];
    }

    private function number(mixed $value, string $key): int|float|null
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (! is_numeric($value)) {
            throw ValidationException::withMessages([
                'measurements' => 'Проверьте числовые значения показателей.',
            ]);
        }

        $number = (float) $value;
        if (in_array($key, ['sys', 'dia', 'pulse', 'spo2'], true)) {
            if ($number !== (float) (int) $number) {
                throw ValidationException::withMessages([
                    'measurements' => 'SYS, DIA, пульс и сатурация вводятся целыми числами.',
                ]);
            }

            return (int) $number;
        }

        return round($number, 2);
    }

    private function state(mixed $value, ?array $threshold): string
    {
        if (! is_numeric($value) || $threshold === null) {
            return 'unknown';
        }

        $number = (float) $value;
        $redLow = $threshold['red_low'] ?? null;
        $redHigh = $threshold['red_high'] ?? null;
        $redLowInclusive = (bool) ($threshold['red_low_inclusive'] ?? false);
        $redHighInclusive = (bool) ($threshold['red_high_inclusive'] ?? false);
        $isRed = ($redLow !== null && ($redLowInclusive ? $number <= (float) $redLow : $number < (float) $redLow))
            || ($redHigh !== null && ($redHighInclusive ? $number >= (float) $redHigh : $number > (float) $redHigh));
        if ($isRed) {
            return 'red';
        }

        $attentionLow = $threshold['attention_low'] ?? null;
        $attentionHigh = $threshold['attention_high'] ?? null;
        if (($attentionLow !== null && $number >= (float) $attentionLow && ($attentionHigh === null || $number <= (float) $attentionHigh))
            || ($attentionHigh !== null && $number <= (float) $attentionHigh && $attentionLow === null)) {
            return 'yellow';
        }

        return 'green';
    }
}
