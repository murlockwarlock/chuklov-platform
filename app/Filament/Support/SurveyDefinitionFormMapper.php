<?php

namespace App\Filament\Support;

use App\Modules\Surveys\Domain\Models\SurveyVersion;
use Illuminate\Support\Str;

final class SurveyDefinitionFormMapper
{
    private const CHOICE_TYPES = ['single_choice', 'multiple_choice'];

    public static function newIdentity(): string
    {
        return (string) Str::uuid();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function normalize(array $data): array
    {
        $sections = is_array($data['sections'] ?? null) ? $data['sections'] : [];
        $questionTypes = SurveyDefinitionFormCompatibility::questionTypes($sections);
        $normalizedSections = [];

        foreach ($sections as $section) {
            if (! is_array($section)) {
                continue;
            }

            $questions = [];
            foreach (is_array($section['questions'] ?? null) ? $section['questions'] : [] as $question) {
                if (! is_array($question)) {
                    continue;
                }

                $type = $question['type'] ?? null;
                $questionData = [
                    'key' => $question['key'] ?? null,
                    'label' => self::localized($question['label'] ?? null, $question['label_en'] ?? null),
                    'type' => $type,
                    'required' => (bool) ($question['required'] ?? false),
                ];

                if (in_array($type, self::CHOICE_TYPES, true)) {
                    $questionData['options'] = [];
                    foreach (is_array($question['options'] ?? null) ? $question['options'] : [] as $option) {
                        if (is_array($option)) {
                            $questionData['options'][] = [
                                'value' => $option['value'] ?? null,
                                'label' => self::localized($option['label'] ?? null, $option['label_en'] ?? null),
                            ];
                        }
                    }
                }

                $condition = self::normalizeCondition($question, $questionTypes);
                if ($condition !== null) {
                    $questionData['condition'] = $condition;
                }

                $questions[] = $questionData;
            }

            $normalizedSections[] = [
                'key' => $section['key'] ?? null,
                'title' => self::localized($section['title'] ?? null, $section['title_en'] ?? null),
                'questions' => $questions,
            ];
        }

        $scoring = is_array($data['legacy_scoring'] ?? null)
            ? $data['legacy_scoring']
            : SurveyDefinitionScoringFormMapper::normalize($data);

        $normalized = [
            'title' => $data['title'] ?? null,
            'title_en' => $data['title_en'] ?? null,
            'description' => $data['description'] ?? null,
            'description_en' => $data['description_en'] ?? null,
            'is_available' => (bool) ($data['is_available'] ?? true),
            'definition' => ['sections' => $normalizedSections],
            'scoring' => $scoring,
            'start_new_metric_scale' => (bool) ($data['start_new_metric_scale'] ?? false),
        ];

        foreach (['source', 'approval_status', 'methodology'] as $metadataKey) {
            if (array_key_exists($metadataKey, $data)) {
                $normalized[$metadataKey] = $data[$metadataKey];
            }
        }

        if (array_key_exists('expected_snapshot', $data)) {
            $normalized['expected_snapshot'] = $data['expected_snapshot'];
        }

        return $normalized;
    }

    /** @return array<string, mixed> */
    public static function denormalize(SurveyVersion $version): array
    {
        $definition = $version->definition;
        $sections = is_array($definition['sections'] ?? null) ? $definition['sections'] : [];
        $questionTypes = SurveyDefinitionFormCompatibility::questionTypes($sections);
        $formSections = [];

        foreach ($sections as $section) {
            if (! is_array($section)) {
                continue;
            }

            $questions = [];
            foreach (is_array($section['questions'] ?? null) ? $section['questions'] : [] as $question) {
                if (! is_array($question)) {
                    continue;
                }

                [$label, $labelEn] = self::denormalizeText($question['label'] ?? null);
                $condition = is_array($question['condition'] ?? null) ? $question['condition'] : null;
                $questionForm = [
                    'key' => $question['key'] ?? null,
                    'label' => $label,
                    'label_en' => $labelEn,
                    'type' => $question['type'] ?? null,
                    'required' => (bool) ($question['required'] ?? false),
                    'options' => [],
                    'condition_question_key' => $condition['question_key'] ?? null,
                    'condition_operator' => $condition['operator'] ?? null,
                    'condition_values' => [],
                    'condition_value' => null,
                    'condition_option_value' => null,
                    'condition_boolean_value' => null,
                ];

                foreach (is_array($question['options'] ?? null) ? $question['options'] : [] as $option) {
                    if (! is_array($option)) {
                        continue;
                    }

                    [$optionLabel, $optionLabelEn] = self::denormalizeText($option['label'] ?? null);
                    $questionForm['options'][] = [
                        'value' => $option['value'] ?? null,
                        'label' => $optionLabel,
                        'label_en' => $optionLabelEn,
                    ];
                }

                if ($condition !== null) {
                    $sourceType = $questionTypes[$condition['question_key'] ?? ''] ?? null;
                    $operator = $condition['operator'] ?? null;
                    $value = $condition['value'] ?? null;

                    if ($operator !== 'answered') {
                        if ($sourceType === 'single_choice' && in_array($operator, ['equals', 'not_equals'], true)) {
                            $questionForm['condition_option_value'] = $value;
                            $questionForm['condition_value'] = $value;
                        } elseif ($sourceType === 'single_choice' && in_array($operator, ['in', 'not_in'], true)) {
                            $questionForm['condition_values'] = is_array($value) ? array_values($value) : [];
                        } elseif ($sourceType === 'boolean' && in_array($operator, ['equals', 'not_equals'], true)) {
                            $questionForm['condition_boolean_value'] = is_bool($value) ? ($value ? 'true' : 'false') : $value;
                            $questionForm['condition_value'] = $value;
                        } else {
                            $questionForm['condition_value'] = $value;
                        }
                    }

                    if (! SurveyDefinitionFormCompatibility::isHumanCondition($condition, $sourceType, $questionTypes)) {
                        $questionForm['condition_legacy'] = $condition;
                    }
                }

                $questions[] = $questionForm;
            }

            [$sectionTitle, $sectionTitleEn] = self::denormalizeText($section['title'] ?? null);
            $formSections[] = [
                'key' => $section['key'] ?? null,
                'title' => $sectionTitle,
                'title_en' => $sectionTitleEn,
                'questions' => $questions,
            ];
        }

        $scoring = $version->scoring;
        $data = [
            'title' => $version->title,
            'title_en' => $version->title_en,
            'description' => $version->description,
            'description_en' => $version->description_en,
            'sections' => $formSections,
            'start_new_metric_scale' => false,
            'source' => $version->source,
            'approval_status' => $version->approval_status,
            'methodology' => $version->methodology,
        ];
        $data = [...$data, ...SurveyDefinitionScoringFormMapper::denormalize($scoring)];

        if (! SurveyDefinitionFormCompatibility::isHumanScoring($definition, $scoring)) {
            $data['legacy_scoring'] = $scoring;
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $question
     * @param  array<string, string>  $questionTypes
     * @return array<string, mixed>|null
     */
    private static function normalizeCondition(array $question, array $questionTypes): ?array
    {
        $legacy = is_array($question['condition_legacy'] ?? null) ? $question['condition_legacy'] : null;
        $questionKey = $question['condition_question_key'] ?? $legacy['question_key'] ?? null;
        $operator = $question['condition_operator'] ?? $legacy['operator'] ?? null;
        if (! is_string($questionKey) || $questionKey === '' || ! is_string($operator) || $operator === '') {
            return null;
        }
        if ($legacy !== null && self::legacyConditionIsUnchanged($question, $questionTypes)) {
            return $legacy;
        }

        $type = $questionTypes[$questionKey] ?? null;
        $condition = ['question_key' => $questionKey, 'operator' => $operator];
        if ($operator === 'answered') {
            return $condition;
        }
        if ($type === 'single_choice' && in_array($operator, ['equals', 'not_equals'], true)) {
            $condition['value'] = $question['condition_option_value'] ?? $question['condition_value'] ?? null;
        } elseif ($type === 'single_choice' && in_array($operator, ['in', 'not_in'], true)) {
            $condition['value'] = array_values(is_array($question['condition_values'] ?? null) ? $question['condition_values'] : []);
        } elseif ($type === 'boolean' && in_array($operator, ['equals', 'not_equals'], true)) {
            $condition['value'] = match ((string) ($question['condition_boolean_value'] ?? '')) {
                'true' => true,
                'false' => false,
                default => $question['condition_boolean_value'] ?? null,
            };
        } elseif ($type === 'integer') {
            $value = $question['condition_value'] ?? null;
            $condition['value'] = self::isWholeIntegerInput($value) ? (int) $value : $value;
        } elseif ($type === 'number' && in_array($operator, ['greater_than', 'less_than'], true) && is_numeric($question['condition_value'] ?? null)) {
            $condition['value'] = (float) $question['condition_value'];
        } else {
            $condition['value'] = $question['condition_value'] ?? null;
        }

        return $condition;
    }

    /**
     * @param  array<string, mixed>  $question
     * @param  array<string, string>  $questionTypes
     */
    private static function legacyConditionIsUnchanged(array $question, array $questionTypes): bool
    {
        $legacy = $question['condition_legacy'];
        $legacyQuestionKey = $legacy['question_key'] ?? null;
        $legacyOperator = $legacy['operator'] ?? null;
        if (($question['condition_question_key'] ?? $legacyQuestionKey) !== $legacyQuestionKey || ($question['condition_operator'] ?? $legacyOperator) !== $legacyOperator) {
            return false;
        }
        if ($legacyOperator === 'answered') {
            return true;
        }

        $type = is_string($legacyQuestionKey) ? ($questionTypes[$legacyQuestionKey] ?? null) : null;
        $formValue = match (true) {
            $type === 'single_choice' && in_array($legacyOperator, ['in', 'not_in'], true) => array_values(is_array($question['condition_values'] ?? $legacy['value'] ?? null) ? ($question['condition_values'] ?? $legacy['value']) : []),
            $type === 'single_choice' && in_array($legacyOperator, ['equals', 'not_equals'], true) => $question['condition_option_value'] ?? $question['condition_value'] ?? ($legacy['value'] ?? null),
            $type === 'boolean' && in_array($legacyOperator, ['equals', 'not_equals'], true) => match ((string) ($question['condition_boolean_value'] ?? $question['condition_value'] ?? '')) {
                'true' => true,
                'false' => false,
                default => $question['condition_boolean_value'] ?? $question['condition_value'] ?? ($legacy['value'] ?? null),
            },
            default => $question['condition_value'] ?? ($legacy['value'] ?? null),
        };

        return $formValue === ($legacy['value'] ?? null);
    }

    public static function isWholeIntegerInput(mixed $value): bool
    {
        if (is_int($value)) {
            return true;
        }

        if (is_float($value)) {
            return is_finite($value) && fmod($value, 1.0) === 0.0;
        }

        return is_string($value) && preg_match('/^[+-]?\d+$/', trim($value)) === 1;
    }

    /** @return string|array{ru: string, en: string} */
    private static function localized(mixed $ru, mixed $en): string|array
    {
        if (! is_string($en) || trim($en) === '') {
            return (string) $ru;
        }

        return ['ru' => (string) $ru, 'en' => $en];
    }

    /** @return array{0: string, 1: string|null} */
    private static function denormalizeText(mixed $value): array
    {
        if (! is_array($value)) {
            return [(string) $value, null];
        }

        return [(string) ($value['ru'] ?? $value['en'] ?? ''), isset($value['en']) ? (string) $value['en'] : null];
    }
}
