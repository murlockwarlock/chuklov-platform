<?php

namespace App\Filament\Resources\Clients\Schemas;

use App\Modules\MedicalProfiles\Application\ClinicalQuestionnaire;
use App\Modules\MedicalProfiles\Application\DTOs\MedicalProfileData;
use App\Modules\MedicalProfiles\Application\DTOs\UpdateMedicalProfileCommand;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Slider;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Utilities\Get;
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
        return [
            'anamnesis' => $profile?->anamnesis,
            'complaints' => $profile?->complaints,
            'goals' => $profile?->goals,
            'operations' => $profile?->operations,
            'injuries' => $profile?->injuries,
            'chronic_conditions' => $profile?->chronicConditions,
            'medicines' => $profile?->medicines,
            'supplements' => $profile?->supplements,
            'pains' => array_map(static fn ($pain): array => [
                'lineage_key' => $pain->lineageKey,
                'name' => $pain->name,
                'score' => $pain->score,
            ], $profile?->pains ?? []),
            'history_entries' => array_map(static fn ($entry): array => [
                'lineage_key' => $entry->lineageKey,
                'type' => $entry->type,
                'year' => $entry->year,
                'description' => $entry->description,
            ], $profile?->historyEntries ?? []),
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
        $answers = is_array($data['answers'] ?? null) ? $data['answers'] : [];
        if (($data['pain_not_measured'] ?? false) === true) {
            $answers['pain_vas'] = null;
        }
        $data['clinical_answers'] = json_encode(app(ClinicalQuestionnaire::class)->validate($answers), JSON_THROW_ON_ERROR);
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
                    Slider::make('score')->label(__('Оценка боли'))->range(minValue: 0, maxValue: 10)->step(1)->nullable()
                        ->helperText(__('Можно оставить без оценки.')),
                ])
                ->columns(2)
                ->defaultItems(0)
                ->reorderable()
                ->collapsible()
                ->itemLabel(fn (array $state): string => (string) ($state['name'] ?? __('Новая боль')))
                ->addActionLabel(__('Добавить боль'))
                ->columnSpanFull(),
            Checkbox::make('pain_not_measured')->label(__('Боль не оценивалась'))->live(),
            Slider::make('answers.pain_vas')->label(__('Шкала боли (ВАШ)'))
                ->range(minValue: 0, maxValue: 10)->step(1)->nullable()->default(null)
                ->disabled(fn (Get $get): bool => $get('pain_not_measured') === true)
                ->helperText(__('0 — нет боли · 10 — нестерпимая боль')),
            Textarea::make('complaints')->label(__('Жалобы'))->rows(3)->maxLength(10000),
            Textarea::make('goals')->label(__('Задачи по здоровью'))->rows(3)->maxLength(10000),
            Checkbox::make('answers.no_chronic_conditions')->label(__('Хронических заболеваний нет')),
            Textarea::make('chronic_conditions')->label(__('Хронические заболевания'))->rows(3)->maxLength(10000),
            Checkbox::make('answers.had_operations')->label(__('Были операции'))->live(),
            TextInput::make('answers.operation_year')->label(__('Год операции'))->integer()->minValue(1900)->maxValue(now()->year)
                ->nullable()->required(fn (Get $get): bool => $get('answers.had_operations') === true)
                ->validationMessages(['required' => __('Укажите год операции.')]),
            Textarea::make('operations')->label(__('Операции'))->rows(3)->maxLength(10000),
            Repeater::make('history_entries')
                ->label(__('Операции и травмы по годам'))
                ->schema([
                    Hidden::make('lineage_key')->dehydrated()->nullable(),
                    Select::make('type')->label(__('Тип'))->options([
                        'operation' => __('Операция'),
                        'injury' => __('Травма'),
                    ])->required(),
                    TextInput::make('year')->label(__('Год'))->integer()->minValue(1800)->maxValue(2200)->required(),
                    Textarea::make('description')->label(__('Описание'))->rows(2)->maxLength(10000)->required(),
                ])
                ->columns(2)
                ->defaultItems(0)
                ->reorderable()
                ->collapsible()
                ->itemLabel(fn (array $state): string => (string) ($state['description'] ?? __('Новая запись')))
                ->addActionLabel(__('Добавить запись'))
                ->columnSpanFull(),
            Checkbox::make('answers.had_injuries')->label(__('Были травмы'))->live(),
            TextInput::make('answers.injury_year')->label(__('Год травмы'))->integer()->minValue(1900)->maxValue(now()->year)
                ->nullable()->required(fn (Get $get): bool => $get('answers.had_injuries') === true)
                ->validationMessages(['required' => __('Укажите год травмы.')]),
            Textarea::make('injuries')->label(__('Травмы'))->rows(3)->maxLength(10000),
            Textarea::make('medicines')->label(__('Лекарственные препараты'))->rows(3)->maxLength(10000),
            Textarea::make('supplements')->label(__('Нутрицевтики и БАДы'))->rows(3)->maxLength(10000),
            Textarea::make('anamnesis')->label(__('Анамнез'))->rows(3)->maxLength(10000),
        ];
    }
}
