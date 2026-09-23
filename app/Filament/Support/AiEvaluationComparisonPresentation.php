<?php

namespace App\Filament\Support;

use App\Modules\AI\Application\Data\AiEvaluationComparison;

final class AiEvaluationComparisonPresentation
{
    public static function summary(AiEvaluationComparison $comparison): string
    {
        if (! $comparison->compatible) {
            return __($comparison->message);
        }

        $lines = [__($comparison->message)];
        $decimalSeparator = app()->getLocale() === 'en' ? '.' : ',';

        foreach ($comparison->runs as $index => $run) {
            $lines[] = __(':run: :prompt · :model · :percentage% passed (:passed of :total)', [
                'run' => __('Запуск :number', ['number' => $index + 1]),
                'prompt' => self::promptLabel((string) ($run['prompt_label'] ?? '')),
                'model' => self::modelLabel((string) ($run['model_label'] ?? '')),
                'percentage' => number_format((float) ($run['pass_percentage'] ?? 0), 2, $decimalSeparator, ''),
                'passed' => (int) ($run['passed_cases'] ?? 0),
                'total' => (int) ($run['total_cases'] ?? 0),
            ]);

            $breakdown = $run['check_breakdown'] ?? [];
            $lines[] = __('Проверки: содержание — :assertion; структура — :schema; источники — :rag; выполнение — :execution.', [
                'assertion' => (int) ($breakdown['assertion'] ?? 0),
                'schema' => (int) ($breakdown['schema'] ?? 0),
                'rag' => (int) ($breakdown['rag'] ?? 0),
                'execution' => (int) ($breakdown['execution'] ?? 0),
            ]);

            $rag = $run['rag'] ?? [];
            $lines[] = __('Источники базы знаний: проверено — :checked; пройдено — :passed; не пройдено — :failed; не проверено — :unchecked.', [
                'checked' => (int) ($rag['checked_cases'] ?? 0),
                'passed' => (int) ($rag['passed_cases'] ?? 0),
                'failed' => (int) ($rag['failed_cases'] ?? 0),
                'unchecked' => (int) ($rag['unchecked_cases'] ?? 0),
            ]);

            $tokens = $run['tokens'] ?? [];
            $lines[] = __('Токены: вход — :input; выход — :output; всего — :total.', [
                'input' => (int) ($tokens['prompt_tokens'] ?? 0),
                'output' => (int) ($tokens['completion_tokens'] ?? 0),
                'total' => (int) ($tokens['total_tokens'] ?? 0),
            ]);

            $lines[] = __('Среднее время: :duration; :estimated; :provider.', [
                'duration' => self::duration((int) ($run['average_latency_ms'] ?? 0), $decimalSeparator),
                'estimated' => self::costLabel(
                    $run['estimated_cost'] ?? [],
                    __('Расчётная стоимость Chuklov'),
                    (int) ($run['estimated_cost_unknown_count'] ?? 0),
                    $decimalSeparator,
                ),
                'provider' => self::costLabel(
                    $run['provider_cost'] ?? [],
                    __('Стоимость AI-сервиса'),
                    (int) ($run['provider_cost_unknown_count'] ?? 0),
                    $decimalSeparator,
                ),
            ]);

            $lines[] = __('Ошибки выполнения: :errors; повторные попытки: :retries; переходы на резервную модель: :failovers.', [
                'errors' => (int) ($run['execution_error_count'] ?? 0),
                'retries' => (int) ($run['retry_count'] ?? 0),
                'failovers' => (int) ($run['failover_count'] ?? 0),
            ]);

            $judgeLabel = (string) ($run['judge']['label'] ?? 'не настроена');
            $lines[] = __('Дополнительная оценка: :status.', ['status' => __($judgeLabel)]);

            $review = $run['human_review'] ?? [];
            $lines[] = __('Проверка специалистом: принято — :accepted (:accepted_rate%); отредактировано и принято — :edited (:edited_rate%); отклонено — :rejected (:rejected_rate%).', [
                'accepted' => (int) ($review['accepted_count'] ?? 0),
                'accepted_rate' => number_format((float) ($review['accepted_rate'] ?? 0), 2, $decimalSeparator, ''),
                'edited' => (int) ($review['edited_and_accepted_count'] ?? 0),
                'edited_rate' => number_format((float) ($review['edited_and_accepted_rate'] ?? 0), 2, $decimalSeparator, ''),
                'rejected' => (int) ($review['rejected_count'] ?? 0),
                'rejected_rate' => number_format((float) ($review['rejected_rate'] ?? 0), 2, $decimalSeparator, ''),
            ]);
        }

        return implode("\n", $lines);
    }

    private static function promptLabel(string $label): string
    {
        if (preg_match('/^Промпт v(\d+)$/u', $label, $matches) === 1) {
            return __('Промпт v:version', ['version' => $matches[1]]);
        }

        return __($label);
    }

    private static function modelLabel(string $label): string
    {
        if ($label === 'Модель недоступна') {
            return __('Модель недоступна');
        }

        if (preg_match('/^(.*) · (.*) · выпуск (.*)$/u', $label, $matches) === 1) {
            return $matches[1].' · '.$matches[2].' · '.__('Выпуск :version', ['version' => $matches[3]]);
        }

        return $label;
    }

    private static function duration(int $milliseconds, string $decimalSeparator): string
    {
        return $milliseconds > 1000
            ? __(':value с', ['value' => number_format($milliseconds / 1000, 2, $decimalSeparator, '')])
            : __(':value мс', ['value' => $milliseconds]);
    }

    private static function costLabel(mixed $costs, string $label, int $unknownCount, string $decimalSeparator): string
    {
        if ($unknownCount > 0) {
            return __(':label: нет данных: стоимость не сообщена или валюта неизвестна', ['label' => $label]);
        }

        if (! is_array($costs) || $costs === []) {
            return __(':label: нет данных', ['label' => $label]);
        }

        $values = [];
        foreach ($costs as $currency => $minorUnits) {
            $values[] = $currency.' '.number_format((int) $minorUnits / 100, 2, $decimalSeparator, ' ');
        }

        return __(':label: :amounts', ['label' => $label, 'amounts' => implode(', ', $values)]);
    }
}
