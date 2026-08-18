<?php

namespace App\Filament\Resources\SurveyDefinitions\Pages;

use App\Filament\Resources\SurveyDefinitions\SurveyDefinitionResource;
use App\Models\User;
use App\Modules\Surveys\Application\PublishSurveyVersion;
use App\Modules\Surveys\Application\UpdateSurveyDefinitionDraft;
use App\Modules\Surveys\Domain\Enums\SurveyVersionStatus;
use App\Modules\Surveys\Domain\Models\SurveyDefinition;
use App\Modules\Surveys\Domain\Models\SurveyVersion;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

final class EditSurveyDefinition extends EditRecord
{
    protected static string $resource = SurveyDefinitionResource::class;

    protected static ?string $title = 'Редактировать тест';

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $record = $this->getRecord();
        abort_unless($record instanceof SurveyDefinition, 404);
        $version = $record->versions()->latest('version')->firstOrFail();

        return [...$data, ...self::denormalize($version)];
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        abort_unless($record instanceof SurveyDefinition, 404);
        $actor = auth()->user();
        abort_unless($actor instanceof User, 403);

        return app(UpdateSurveyDefinitionDraft::class)->handle($actor, $record, self::normalize($data));
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('publish')->label('Опубликовать черновик')->icon('heroicon-o-check-circle')->requiresConfirmation()->action(function (): void {
                $actor = auth()->user();
                abort_unless($actor instanceof User, 403);
                $record = $this->getRecord();
                abort_unless($record instanceof SurveyDefinition, 404);
                $draft = $record->versions()->where('status', SurveyVersionStatus::Draft)->latest('version')->first();
                abort_unless($draft instanceof SurveyVersion, 422, 'Сначала сохраните новый черновик.');
                app(PublishSurveyVersion::class)->handle($actor, $draft);
                Notification::make()->title('Версия опубликована')->success()->send();
                $this->redirect(SurveyDefinitionResource::getUrl('edit', ['record' => $record]));
            }),
        ];
    }

    /** @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public static function normalize(array $data): array
    {
        $sections = array_map(function (array $section): array {
            $section['questions'] = array_map(function (array $question): array {
                $condition = null;
                if (($question['condition_question_key'] ?? '') !== '' && ($question['condition_operator'] ?? '') !== '') {
                    $condition = [
                        'question_key' => $question['condition_question_key'],
                        'operator' => $question['condition_operator'],
                        'value' => in_array($question['condition_operator'], ['in', 'not_in'], true)
                            ? array_values($question['condition_values'] ?? [])
                            : ($question['condition_value'] ?? null),
                    ];
                }

                $options = array_map(static fn (array $option): array => [
                    'value' => $option['value'],
                    'label' => self::localized($option['label'] ?? null, $option['label_en'] ?? null),
                ], $question['options'] ?? []);

                return array_filter([
                    'key' => $question['key'], 'label' => self::localized($question['label'] ?? null, $question['label_en'] ?? null), 'type' => $question['type'],
                    'required' => (bool) ($question['required'] ?? false), 'options' => $options === [] ? null : $options, 'condition' => $condition,
                ], static fn (mixed $value): bool => $value !== null);
            }, $section['questions'] ?? []);

            return ['key' => $section['key'], 'title' => self::localized($section['title'] ?? null, $section['title_en'] ?? null), 'questions' => $section['questions']];
        }, $data['sections'] ?? []);
        $rules = array_map(function (array $rule): array {
            $points = [];
            foreach ($rule['points'] ?? [] as $point) {
                $points[(string) $point['value']] = (float) $point['points'];
            }

            return array_filter([
                'question_key' => $rule['question_key'], 'metric_key' => $rule['metric_key'], 'operator' => $rule['operator'],
                'points' => $points === [] ? null : $points, 'multiplier' => isset($rule['multiplier']) ? (float) $rule['multiplier'] : null,
            ], static fn (mixed $value): bool => $value !== null);
        }, $data['rules'] ?? []);

        $metrics = array_map(static fn (array $metric): array => [
            'key' => $metric['key'],
            'label' => self::localized($metric['label'] ?? null, $metric['label_en'] ?? null),
        ], $data['metrics'] ?? []);
        $thresholds = array_map(static fn (array $threshold): array => array_filter([
            'metric_key' => $threshold['metric_key'] ?? null,
            'min' => isset($threshold['min']) && $threshold['min'] !== '' ? (float) $threshold['min'] : null,
            'max' => isset($threshold['max']) && $threshold['max'] !== '' ? (float) $threshold['max'] : null,
            'tag' => $threshold['tag'] ?? null,
            'label' => self::localized($threshold['label'] ?? null, $threshold['label_en'] ?? null),
        ], static fn (mixed $value): bool => $value !== null), $data['thresholds'] ?? []);

        return [...$data, 'definition' => ['sections' => $sections], 'scoring' => [
            'metrics' => $metrics, 'rules' => $rules, 'thresholds' => $thresholds,
            'comparison' => ($data['comparison_metric_keys'] ?? []) === [] ? null : ['operator' => 'no_decrease', 'metric_keys' => array_values($data['comparison_metric_keys'])],
        ]];
    }

    /** @return array<string, mixed> */
    private static function denormalize(SurveyVersion $version): array
    {
        $sections = $version->definition['sections'] ?? [];
        foreach ($sections as &$section) {
            [$section['title'], $section['title_en']] = self::denormalizeText($section['title'] ?? null);
            foreach ($section['questions'] as &$question) {
                [$question['label'], $question['label_en']] = self::denormalizeText($question['label'] ?? null);
                foreach ($question['options'] ?? [] as &$option) {
                    [$option['label'], $option['label_en']] = self::denormalizeText($option['label'] ?? null);
                }
                $condition = $question['condition'] ?? [];
                $question['condition_question_key'] = $condition['question_key'] ?? null;
                $question['condition_operator'] = $condition['operator'] ?? null;
                $question['condition_values'] = in_array($question['condition_operator'], ['in', 'not_in'], true)
                    ? (is_array($condition['value'] ?? null) ? $condition['value'] : [])
                    : [];
                $question['condition_value'] = $question['condition_values'] === [] ? ($condition['value'] ?? null) : null;
            }
        }
        $rules = $version->scoring['rules'] ?? [];
        foreach ($rules as &$rule) {
            $points = [];
            foreach ($rule['points'] ?? [] as $value => $score) {
                $points[] = ['value' => $value, 'points' => $score];
            }
            $rule['points'] = $points;
        }
        $metrics = $version->scoring['metrics'] ?? [];
        foreach ($metrics as &$metric) {
            [$metric['label'], $metric['label_en']] = self::denormalizeText($metric['label'] ?? null);
        }
        $thresholds = $version->scoring['thresholds'] ?? [];
        foreach ($thresholds as &$threshold) {
            [$threshold['label'], $threshold['label_en']] = self::denormalizeText($threshold['label'] ?? null);
        }

        return [
            'title' => $version->title,
            'title_en' => $version->title_en,
            'description' => $version->description,
            'description_en' => $version->description_en,
            'metric_schema_key' => $version->metric_schema_key, 'source_reference' => $version->source_reference,
            'sections' => $sections, 'metrics' => $metrics, 'rules' => $rules,
            'thresholds' => $thresholds, 'comparison_metric_keys' => $version->scoring['comparison']['metric_keys'] ?? [],
        ];
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
