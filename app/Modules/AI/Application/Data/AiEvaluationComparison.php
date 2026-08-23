<?php

namespace App\Modules\AI\Application\Data;

final readonly class AiEvaluationComparison
{
    /**
     * @param  list<array<string, mixed>>  $runs
     */
    public function __construct(
        public bool $compatible,
        public string $message,
        public array $runs = [],
    ) {}

    public function toRussianSummary(): string
    {
        if (! $this->compatible) {
            return $this->message;
        }

        $lines = [$this->message];
        foreach ($this->runs as $index => $run) {
            $result = sprintf(
                '%s: %s · %s · %s (%d из %d)',
                'Запуск '.($index + 1),
                $run['prompt_label'],
                $run['model_label'],
                number_format((float) $run['pass_percentage'], 2, ',', '').'% пройдено',
                (int) $run['passed_cases'],
                (int) $run['total_cases'],
            );
            $lines[] = $result;
            $breakdown = $run['check_breakdown'] ?? [];
            $lines[] = 'Проверки: содержание — '.(int) ($breakdown['assertion'] ?? 0).'; структура — '.(int) ($breakdown['schema'] ?? 0).'; источники — '.(int) ($breakdown['rag'] ?? 0).'; выполнение — '.(int) ($breakdown['execution'] ?? 0).'.';
            $rag = $run['rag'] ?? [];
            $lines[] = 'Источники базы знаний: проверено — '.(int) ($rag['checked_cases'] ?? 0).'; пройдено — '.(int) ($rag['passed_cases'] ?? 0).'; не пройдено — '.(int) ($rag['failed_cases'] ?? 0).'.';
            $lines[] = 'Среднее время: '.self::duration((int) ($run['average_latency_ms'] ?? 0)).'; '.self::costLabel($run['estimated_cost'] ?? [], 'Расчётная стоимость Chuklov').'; '.self::costLabel($run['provider_cost'] ?? [], 'Стоимость от провайдера').'.';
            $lines[] = 'Ошибки выполнения: '.(int) ($run['execution_error_count'] ?? 0).'; повторные попытки: '.(int) ($run['retry_count'] ?? 0).'; переходы на резервную модель: '.(int) ($run['failover_count'] ?? 0).'.';
            $lines[] = 'Проверка специалистом: принято — '.(int) ($run['human_review']['accepted_count'] ?? 0).' ('.number_format((float) ($run['human_review']['accepted_rate'] ?? 0), 2, ',', '').'%), отредактировано и принято — '.(int) ($run['human_review']['edited_and_accepted_count'] ?? 0).' ('.number_format((float) ($run['human_review']['edited_and_accepted_rate'] ?? 0), 2, ',', '').'%), отклонено — '.(int) ($run['human_review']['rejected_count'] ?? 0).' ('.number_format((float) ($run['human_review']['rejected_rate'] ?? 0), 2, ',', '').'%).';
        }

        return implode("\n", $lines);
    }

    private static function duration(int $milliseconds): string
    {
        return $milliseconds > 1000
            ? number_format($milliseconds / 1000, 2, ',', '').' с'
            : $milliseconds.' мс';
    }

    /** @param array<string, mixed> $costs */
    private static function costLabel(array $costs, string $label): string
    {
        if ($costs === []) {
            return $label.': нет данных';
        }

        $values = [];
        foreach ($costs as $currency => $minorUnits) {
            $values[] = $currency.' '.number_format((int) $minorUnits / 100, 2, ',', ' ');
        }

        return $label.': '.implode(', ', $values);
    }
}
