<?php

namespace App\Modules\Surveys\Application;

final class ProjectSurveyContent
{
    /**
     * @param  array<string, mixed>  $definition
     * @return array<string, mixed>
     */
    public function definition(array $definition, string $locale): array
    {
        foreach ($definition['sections'] ?? [] as $sectionIndex => $section) {
            if (! is_array($section)) {
                continue;
            }
            $definition['sections'][$sectionIndex]['title'] = $this->text($section['title'] ?? '', $locale);
            foreach ($section['questions'] ?? [] as $questionIndex => $question) {
                if (! is_array($question)) {
                    continue;
                }
                $definition['sections'][$sectionIndex]['questions'][$questionIndex]['label'] = $this->text($question['label'] ?? '', $locale);
                foreach ($question['options'] ?? [] as $optionIndex => $option) {
                    if (is_array($option)) {
                        $definition['sections'][$sectionIndex]['questions'][$questionIndex]['options'][$optionIndex]['label'] = $this->text($option['label'] ?? '', $locale);
                    }
                }
            }
        }

        return $definition;
    }

    /**
     * @param  array<string, mixed>  $report
     * @return array<string, mixed>
     */
    public function report(array $report, string $locale): array
    {
        $report['title'] = $this->text($report['survey']['title'] ?? '', $locale);
        foreach ($report['metrics'] ?? [] as $key => $metric) {
            if (is_array($metric)) {
                $report['metrics'][$key]['label'] = $this->text($metric['label'] ?? $key, $locale);
            }
        }
        foreach ($report['thresholds'] ?? [] as $key => $threshold) {
            if (is_array($threshold)) {
                $report['thresholds'][$key]['label'] = $this->text($threshold['label'] ?? $threshold['tag'] ?? '', $locale);
            }
        }

        return $report;
    }

    private function text(mixed $value, string $locale): string
    {
        if (is_string($value)) {
            return $value;
        }
        if (! is_array($value)) {
            return '';
        }
        $primary = str_starts_with(strtolower($locale), 'en') ? 'en' : 'ru';
        $secondary = $primary === 'en' ? 'ru' : 'en';

        return is_string($value[$primary] ?? null) ? $value[$primary] : (is_string($value[$secondary] ?? null) ? $value[$secondary] : '');
    }
}
