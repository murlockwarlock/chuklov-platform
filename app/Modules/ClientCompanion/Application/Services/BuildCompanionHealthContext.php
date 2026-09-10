<?php

namespace App\Modules\ClientCompanion\Application\Services;

use App\Modules\Surveys\Application\ProjectSurveyContent;
use App\Modules\Surveys\Domain\Models\SurveyReport;

final class BuildCompanionHealthContext
{
    public function __construct(private readonly ProjectSurveyContent $content) {}

    public function handle(int $organizationId, int $clientId, string $locale): string
    {
        $reports = SurveyReport::query()
            ->where('organization_id', $organizationId)
            ->where('client_id', $clientId)
            ->orderByDesc('materialized_at')
            ->orderByDesc('id')
            ->limit(2)
            ->get();

        $parts = [];
        foreach ($reports as $report) {
            $snapshot = $this->content->report($report->report_snapshot, $locale);
            $formatted = $this->formatReport($snapshot, $locale);
            if ($formatted !== '') {
                $parts[] = $formatted;
            }
        }

        if ($parts === []) {
            return '';
        }

        return $this->truncate(implode("\n\n", $parts), 7000);
    }

    /** @param array<string, mixed> $report */
    private function formatReport(array $report, string $locale): string
    {
        $english = str_starts_with(strtolower($locale), 'en');
        $parts = [];
        $title = trim((string) ($report['title'] ?? ''));
        if ($title !== '') {
            $parts[] = ($english ? 'Test result: ' : 'Результат теста: ').$title;
        }

        $summary = trim((string) data_get($report, 'summary.short', ''));
        if ($summary !== '') {
            $parts[] = ($english ? 'Summary: ' : 'Резюме: ').$summary;
        }

        $areas = [];
        foreach ((array) ($report['attention_areas'] ?? []) as $area) {
            if (! is_array($area)) {
                continue;
            }
            $label = trim((string) ($area['label'] ?? ''));
            if ($label === '') {
                continue;
            }
            $line = $label;
            if (is_numeric($area['score'] ?? null)) {
                $line .= ': '.(int) $area['score'].'/100';
            }
            foreach (['status', 'reason', 'observation'] as $field) {
                $value = trim((string) ($area[$field] ?? ''));
                if ($value !== '') {
                    $line .= '; '.$value;
                }
            }
            $evidence = [];
            foreach ((array) ($area['evidence'] ?? []) as $item) {
                if (! is_array($item)) {
                    continue;
                }
                $question = trim((string) ($item['question'] ?? ''));
                $answer = trim((string) ($item['answer'] ?? ''));
                if ($question !== '' && $answer !== '') {
                    $evidence[] = $question.' — '.$answer;
                }
            }
            if ($evidence !== []) {
                $line .= '; '.($english ? 'answers: ' : 'ответы: ').implode(', ', array_slice($evidence, 0, 3));
            }
            $areas[] = '- '.$line;
        }
        if ($areas !== []) {
            $parts[] = ($english ? "Areas to notice:\n" : "Зоны внимания:\n").implode("\n", $areas);
        }

        $roadMap = [];
        foreach ((array) data_get($report, 'road_map.items', []) as $item) {
            if (! is_array($item)) {
                continue;
            }
            $itemTitle = trim((string) ($item['title'] ?? ''));
            $description = trim((string) ($item['description'] ?? ''));
            if ($itemTitle !== '') {
                $roadMap[] = '- '.$itemTitle.($description === '' ? '' : ': '.$description);
            }
        }
        if ($roadMap !== []) {
            $parts[] = "Road Map:\n".implode("\n", array_slice($roadMap, 0, 4));
        }

        $comparison = $report['comparison'] ?? null;
        if (is_array($comparison)) {
            $message = trim((string) ($comparison['message'] ?? ''));
            if ($message !== '') {
                $parts[] = ($english ? 'Trend: ' : 'Динамика: ').$message;
            }
        }

        return implode("\n", $parts);
    }

    private function truncate(string $value, int $limit): string
    {
        return mb_strlen($value) > $limit ? mb_substr($value, 0, $limit - 1).'…' : $value;
    }
}
