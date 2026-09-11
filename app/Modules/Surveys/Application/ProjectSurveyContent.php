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
        $localizedReport = $this->localizedValue($report, $locale);
        if (is_array($localizedReport)) {
            $report = $localizedReport;
        }
        $report['title'] = is_array($report['survey'] ?? null)
            ? ($report['survey']['title'] ?? '')
            : '';

        return $report;
    }

    private function localizedValue(mixed $value, string $locale): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (array_key_exists('ru', $value) || array_key_exists('en', $value)) {
            $localized = $this->text($value, $locale);
            if ($localized !== '') {
                return $localized;
            }
        }

        foreach ($value as $key => $item) {
            $value[$key] = $this->localizedValue($item, $locale);
        }

        return $value;
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
