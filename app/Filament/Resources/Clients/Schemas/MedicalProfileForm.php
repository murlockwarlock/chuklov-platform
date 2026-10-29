<?php

namespace App\Filament\Resources\Clients\Schemas;

use App\Filament\Pages\MedicalClinicalCatalogConfiguration;
use App\Modules\MedicalProfiles\Application\ClinicalQuestionnaire;
use App\Modules\MedicalProfiles\Application\DTOs\MedicalProfileData;
use App\Modules\MedicalProfiles\Application\DTOs\UpdateMedicalProfileCommand;
use App\Modules\MedicalProfiles\Application\MedicalProblemTagCatalog;
use App\Modules\Organizations\Application\OrganizationContext;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Slider;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use JsonException;

final class MedicalProfileForm
{
    public const SYMPTOMS = [
        'headache' => 'Головные боли', 'neck' => 'Боль в шее', 'back' => 'Боль в пояснице',
        'joints' => 'Суставы', 'swelling' => 'Отечность', 'digestion' => 'Проблемы ЖКТ',
        'weakness' => 'Сонливость/Слабость', 'insomnia' => 'Бессонница', 'stress' => 'Стресс/Тревожность',
    ];

    /** @return array<string, mixed> */
    public static function fill(?MedicalProfileData $profile): array
    {
        $pains = $profile instanceof MedicalProfileData ? $profile->pains : [];
        $historyEntries = $profile instanceof MedicalProfileData ? $profile->historyEntries : [];
        $historyRows = array_map(static fn ($entry): array => [
            'lineage_key' => $entry->lineageKey,
            'type' => $entry->type,
            'year' => $entry->year,
            'description' => $entry->description,
        ], $historyEntries);

        return [
            'anamnesis' => $profile?->anamnesis,
            'complaints' => $profile?->complaints,
            'goals' => $profile?->goals,
            'legacy_operations' => $profile?->operations,
            'legacy_injuries' => $profile?->injuries,
            'operations' => $profile?->operations,
            'injuries' => $profile?->injuries,
            'chronic_conditions' => $profile?->chronicConditions,
            'medicines' => $profile?->medicines,
            'supplements' => $profile?->supplements,
            'pains' => array_map(static fn ($pain): array => [
                'lineage_key' => $pain->lineageKey,
                'name' => $pain->name,
                'score' => $pain->score,
                'tag_ids' => $pain->tagIds,
            ], $pains),
            'history_entries' => $historyRows,
            'operation_history' => array_values(array_filter($historyRows, static fn (array $entry): bool => $entry['type'] === 'operation')),
            'injury_history' => array_values(array_filter($historyRows, static fn (array $entry): bool => $entry['type'] === 'injury')),
            'pain_not_measured' => (self::answers($profile)['pain_vas'] ?? null) === null,
            'answers' => self::answers($profile) ?? [
                'symptoms' => [], 'pain_vas' => null, 'no_chronic_conditions' => false,
                'had_operations' => false, 'operation_year' => null,
                'had_injuries' => false, 'injury_year' => null,
            ],
        ];
    }

    /** @return array<string, mixed>|null */
    public static function answers(?MedicalProfileData $profile): ?array
    {
        if ($profile?->clinicalAnswers === null) {
            return null;
        }

        try {
            $answers = json_decode($profile->clinicalAnswers, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        return is_array($answers) ? $answers : null;
    }

    /** @param array<string, mixed> $data */
    public static function command(array $data): UpdateMedicalProfileCommand
    {
        $historyEntries = is_array($data['history_entries'] ?? null) ? array_values($data['history_entries']) : [];
        foreach (['operation_history' => 'operation', 'injury_history' => 'injury'] as $field => $type) {
            if (! array_key_exists($field, $data)) {
                continue;
            }

            $historyEntries = array_values(array_filter(
                $historyEntries,
                static fn (mixed $entry): bool => ! is_array($entry) || ($entry['type'] ?? null) !== $type,
            ));
            foreach (array_values(is_array($data[$field]) ? $data[$field] : []) as $entry) {
                $historyEntries[] = is_array($entry)
                    ? [...$entry, 'type' => $type]
                    : $entry;
            }
        }
        if (array_key_exists('history_entries', $data) || array_key_exists('operation_history', $data) || array_key_exists('injury_history', $data)) {
            $data['history_entries'] = $historyEntries;
        }
        unset($data['operation_history'], $data['injury_history']);

        $answers = is_array($data['answers'] ?? null) ? $data['answers'] : [];
        if (($data['pain_not_measured'] ?? false) === true) {
            $answers['pain_vas'] = null;
        }
        $data['clinical_answers'] = json_encode(app(ClinicalQuestionnaire::class)->validate($answers, false), JSON_THROW_ON_ERROR);
        unset($data['answers']);
        unset($data['pain_not_measured']);

        return UpdateMedicalProfileCommand::fromArray($data);
    }

    /** @return list<Component> */
    public static function components(): array
    {
        return [
            Hidden::make('expected_snapshot')->dehydrated()->nullable()->string(),
            CheckboxList::make('answers.symptoms')->label(__('Главные жалобы (симптомы)'))
                ->options(array_map(static fn (string $label): string => __($label), self::SYMPTOMS))->columns(2),
            Repeater::make('pains')
                ->label(__('Боли и оценка клиента'))
                ->schema([
                    Hidden::make('lineage_key')->dehydrated()->nullable(),
                    TextInput::make('name')->label(__('Название или описание боли'))->required()->maxLength(1000),
                    Slider::make('score')
                        ->label(__('Оценка боли'))
                        ->range(minValue: 0, maxValue: 10)
                        ->step(1)
                        ->nullable()
                        ->live()
                        ->helperText(__('0 — нет боли · 10 — максимальная боль')),
                    Placeholder::make('score_display')
                        ->label(__('Текущее значение'))
                        ->content(fn (Get $get): string => self::painScoreDisplay($get('score'))),
                    Actions::make([
                        Action::make('clearPainScore')
                            ->label(__('Без оценки'))
                            ->icon('heroicon-o-x-mark')
                            ->color('gray')
                            ->action(static function (Set $set): void {
                                $set('score', null);
                            })
                            ->visible(fn (Get $get): bool => $get('score') !== null),
                    ])->columnSpanFull(),
                    Select::make('tag_ids')
                        ->label(__('Категория проблемы'))
                        ->options(fn (Get $get): array => app(MedicalProblemTagCatalog::class)->optionsForSelection(app(OrganizationContext::class)->id(), (array) $get('tag_ids')))
                        ->multiple()
                        ->searchable()
                        ->preload()
                        ->helperText(fn (Get $get): string => self::problemTagHelperText($get('tag_ids')))
                        ->hintAction(self::problemTagConfigurationAction()),
                ])
                ->columns(2)
                ->defaultItems(0)
                ->reorderable()
                ->collapsible()
                ->itemLabel(fn (array $state): string => (string) ($state['name'] ?? __('Новая боль')))
                ->addActionLabel(__('Добавить боль'))
                ->columnSpanFull(),
            Hidden::make('answers.pain_vas')->dehydrated()->nullable(),
            Textarea::make('complaints')->label(__('Жалобы'))->rows(3)->maxLength(10000),
            Textarea::make('goals')->label(__('Задачи по здоровью'))->rows(3)->maxLength(10000),
            Checkbox::make('answers.no_chronic_conditions')->label(__('Хронических заболеваний нет')),
            Textarea::make('chronic_conditions')->label(__('Хронические заболевания'))->rows(3)->maxLength(10000),
            Checkbox::make('answers.had_operations')->label(__('Были операции'))->live(),
            Hidden::make('answers.operation_year')->dehydrated()->nullable(),
            Hidden::make('operations')->dehydrated(),
            Hidden::make('legacy_operations')->dehydrated(false)->visible(false),
            Placeholder::make('legacy_operations_display')
                ->label(__('Исторические данные об операциях'))
                ->content(fn (Get $get): string => (string) $get('legacy_operations'))
                ->visible(fn (Get $get): bool => filled($get('legacy_operations')))
                ->columnSpanFull(),
            Hidden::make('history_entries')->dehydrated()->default([]),
            self::historyRepeater('operation_history', __('Операции'), 'operation'),
            self::historyRepeater('injury_history', __('Травмы'), 'injury'),
            Checkbox::make('answers.had_injuries')->label(__('Были травмы'))->live(),
            Hidden::make('answers.injury_year')->dehydrated()->nullable(),
            Hidden::make('injuries')->dehydrated(),
            Hidden::make('legacy_injuries')->dehydrated(false)->visible(false),
            Placeholder::make('legacy_injuries_display')
                ->label(__('Исторические данные о травмах'))
                ->content(fn (Get $get): string => (string) $get('legacy_injuries'))
                ->visible(fn (Get $get): bool => filled($get('legacy_injuries')))
                ->columnSpanFull(),
            Textarea::make('medicines')->label(__('Лекарственные препараты'))->rows(3)->maxLength(10000),
            Textarea::make('supplements')->label(__('Нутрицевтики и БАДы'))->rows(3)->maxLength(10000),
            Textarea::make('anamnesis')->label(__('Анамнез'))->rows(3)->maxLength(10000)->live(onBlur: true),
        ];
    }

    private static function historyRepeater(string $name, string $label, string $type): Repeater
    {
        $toggle = $type === 'operation' ? 'answers.had_operations' : 'answers.had_injuries';

        return Repeater::make($name)
            ->label($label)
            ->helperText(__('Добавляйте отдельную строку для каждой записи.'))
            ->schema([
                Hidden::make('lineage_key')->dehydrated()->nullable(),
                TextInput::make('year')->label(__('Год'))->integer()->minValue(1800)->maxValue(2200)->required()->live(),
                Textarea::make('description')->label(__('Описание'))->rows(2)->maxLength(10000)->required()->live(),
            ])
            ->columns(2)
            ->defaultItems(0)
            ->reorderable()
            ->collapsible()
            ->itemLabel(fn (array $state): string => self::historyItemLabel($state, $type))
            ->addActionLabel($type === 'operation' ? __('Добавить операцию') : __('Добавить травму'))
            ->visible(fn (Get $get): bool => $get($toggle) === true
                || is_array($get($name)) && $get($name) !== [])
            ->columnSpanFull();
    }

    private static function painScoreDisplay(mixed $value): string
    {
        return is_numeric($value) && (int) $value >= 0 && (int) $value <= 10
            ? __(':value / 10', ['value' => (int) $value])
            : __('Без оценки');
    }

    /** @param array<string, mixed> $state */
    private static function historyItemLabel(array $state, string $type): string
    {
        $fallback = $type === 'operation' ? __('Новая операция') : __('Новая травма');
        $year = is_numeric($state['year'] ?? null) ? (int) $state['year'] : null;
        $description = trim((string) ($state['description'] ?? ''));

        if ($year === null || $description === '') {
            return $fallback;
        }

        return $year.' — '.$description;
    }

    /** @return array<int, string> */
    private static function problemTagOptions(mixed $selectedIds): array
    {
        return app(MedicalProblemTagCatalog::class)->optionsForSelection(
            app(OrganizationContext::class)->id(),
            is_array($selectedIds) ? $selectedIds : [],
        );
    }

    private static function problemTagHelperText(mixed $selectedIds): string
    {
        return self::problemTagOptions($selectedIds) === []
            ? __('Категории проблем ещё не настроены.')
            : __('Выберите одну или несколько категорий для статистики.');
    }

    private static function problemTagConfigurationAction(): Action
    {
        return Action::make('configureProblemCategories')
            ->label(__('Настроить категории'))
            ->url(MedicalClinicalCatalogConfiguration::getUrl())
            ->visible(fn (): bool => self::problemTagOptions([]) === [] && MedicalClinicalCatalogConfiguration::canAccess());
    }
}
