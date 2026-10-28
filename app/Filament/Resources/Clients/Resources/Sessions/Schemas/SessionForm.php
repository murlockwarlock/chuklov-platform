<?php

namespace App\Filament\Resources\Clients\Resources\Sessions\Schemas;

use App\Modules\Identity\Domain\Models\Client;
use App\Modules\MedicalProfiles\Application\MedicalFrequencyPeriodCatalog;
use App\Modules\MedicalProfiles\Application\MedicalProblemTagCatalog;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Scheduling\Domain\Models\Booking;
use App\Modules\Sessions\Application\MedicalClinicalCatalog;
use App\Modules\Specialists\Domain\Models\Specialist;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Slider;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

final class SessionForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('Сеанс'))
                    ->schema([
                        DateTimePicker::make('occurred_at')
                            ->label(__('Дата и время сеанса'))
                            ->timezone(fn (): string => app(OrganizationContext::class)->defaultTimezone())
                            ->required()
                            ->seconds(false)
                            ->placeholder(__('Выберите дату и время сеанса')),
                        Select::make('specialist_id')
                            ->label(__('Специалист'))
                            ->required()
                            ->searchable()
                            ->native(false)
                            ->preload()
                            ->optionsLimit(50)
                            ->options(static fn (): array => self::specialistResults(''))
                            ->getSearchResultsUsing(static fn (string $search): array => self::specialistResults($search))
                            ->getOptionLabelUsing(static fn (mixed $value): ?string => self::specialistLabel($value)),
                        Select::make('booking_id')
                            ->label(__('Запись на приём (необязательно)'))
                            ->helperText(__('Связан только с записями этого клиента и выбранного специалиста.'))
                            ->placeholder(__('Без записи на приём'))
                            ->searchable()
                            ->native(false)
                            ->getSearchResultsUsing(static fn (string $search, Select $component): array => self::bookingResults($search, $component))
                            ->getOptionLabelUsing(static fn (mixed $value, Select $component): ?string => self::bookingLabel($value, $component)),
                    ])->columns(2)->columnSpanFull(),
                Section::make(__('Клинические заметки (опционально)'))
                    ->schema([
                        Repeater::make('pains')
                            ->label(__('Боли'))
                            ->schema([
                                Hidden::make('lineage_key')->dehydrated()->nullable(),
                                TextInput::make('name')->label(__('Название или описание боли'))->required()->maxLength(1000),
                                Select::make('frequency_mode')->label(__('Частота'))->options([
                                    'permanent' => __('Постоянно'),
                                    'count' => __('Количество раз за период'),
                                ])->required()->default('permanent')->live(),
                                TextInput::make('frequency_count')->label(__('Количество'))->integer()->minValue(1)
                                    ->visible(fn (Get $get): bool => $get('frequency_mode') === 'count')
                                    ->required(fn (Get $get): bool => $get('frequency_mode') === 'count'),
                                Select::make('frequency_period_unit')->label(__('Период'))->options(fn (Get $get): array => app(MedicalFrequencyPeriodCatalog::class)->optionsForSelection(app(OrganizationContext::class)->id(), $get('frequency_period_unit')))
                                    ->visible(fn (Get $get): bool => $get('frequency_mode') === 'count')
                                    ->required(fn (Get $get): bool => $get('frequency_mode') === 'count')
                                    ->searchable(),
                                CheckboxList::make('time_of_day')->label(__('Время суток'))->options([
                                    'all' => __('Весь день'),
                                    'morning' => __('Утро'),
                                    'day' => __('День'),
                                    'evening' => __('Вечер'),
                                    'night' => __('Ночь'),
                                ])->columns(2)->default(['all'])->required(),
                                Slider::make('before_score')->label(__('До сеанса'))->range(minValue: 0, maxValue: 10)->step(1)->nullable(),
                                Slider::make('after_score')->label(__('После сеанса'))->range(minValue: 0, maxValue: 10)->step(1)->nullable(),
                                Placeholder::make('previous_after_score')
                                    ->label(__('После предыдущего сеанса'))
                                    ->content(fn (Get $get): string => self::scoreDisplay(self::previousAfterScore($get))),
                                Select::make('tag_ids')
                                    ->label(__('Категория проблемы'))
                                    ->options(fn (): array => app(MedicalProblemTagCatalog::class)->options(app(OrganizationContext::class)->id()))
                                    ->multiple()
                                    ->searchable()
                                    ->preload(),
                            ])
                            ->columns(2)
                            ->defaultItems(0)
                            ->reorderable()
                            ->collapsible()
                            ->itemLabel(fn (array $state): string => (string) ($state['name'] ?? __('Новая боль')))
                            ->addActionLabel(__('Добавить боль'))
                            ->columnSpanFull(),
                        Textarea::make('pain')->label(__('Особые жалобы'))->rows(3)->placeholder(__('Что ещё важно учесть специалисту')),
                        Repeater::make('test_results')
                            ->label(__('Кинезиологические и мануальные тесты'))
                            ->schema([
                                Select::make('test_id')
                                    ->label(__('Тест'))
                                    ->options(fn (): array => app(MedicalClinicalCatalog::class)->testOptions(app(OrganizationContext::class)->id()))
                                    ->required()
                                    ->searchable()
                                    ->live(),
                                Select::make('before_value_id')
                                    ->label(__('До сеанса'))
                                    ->options(fn (Get $get): array => app(MedicalClinicalCatalog::class)->valueOptions(app(OrganizationContext::class)->id(), (int) $get('test_id')))
                                    ->nullable()
                                    ->searchable(),
                                Select::make('after_value_id')
                                    ->label(__('После сеанса'))
                                    ->options(fn (Get $get): array => app(MedicalClinicalCatalog::class)->valueOptions(app(OrganizationContext::class)->id(), (int) $get('test_id')))
                                    ->nullable()
                                    ->searchable(),
                                Select::make('cause_ids')
                                    ->label(__('Причины нарушений'))
                                    ->options(fn (): array => app(MedicalClinicalCatalog::class)->causeOptions(app(OrganizationContext::class)->id()))
                                    ->multiple()
                                    ->searchable(),
                            ])
                            ->columns(2)
                            ->defaultItems(0)
                            ->reorderable()
                            ->collapsible()
                            ->itemLabel(fn (array $state): string => filled($state['test_id'] ?? null) ? __('Выбранный тест') : __('Новый тест'))
                            ->addActionLabel(__('Добавить тест'))
                            ->columnSpanFull(),
                        Textarea::make('tests')->label(__('Тесты'))->rows(3)->placeholder(__('Проведённые проверки и их результаты')),
                        Textarea::make('observations')->label(__('Наблюдения'))->rows(3)->placeholder(__('Дополнительные наблюдения')),
                        Repeater::make('root_causes')
                            ->label(__('Первопричина нарушений'))
                            ->schema([
                                Select::make('cause_id')
                                    ->label(__('Причина из сеанса'))
                                    ->options(fn (Get $get): array => self::rootCauseOptions($get))
                                    ->searchable()
                                    ->nullable(),
                                Textarea::make('free_text')->label(__('Дополнительное описание'))->rows(2)->maxLength(10000),
                            ])
                            ->columns(2)
                            ->defaultItems(0)
                            ->maxItems(2)
                            ->reorderable()
                            ->addActionLabel(__('Добавить первопричину'))
                            ->columnSpanFull(),
                        Textarea::make('root_cause_hypothesis')->label(__('Гипотеза первопричины'))->rows(3)->placeholder(__('Предполагаемая причина состояния')),
                        Hidden::make('pain_vas')->dehydrated()->nullable(),
                        Hidden::make('pain_not_measured')->dehydrated()->default(true),
                        Placeholder::make('pain_vas_legacy')
                            ->label(__('Историческое значение ВАШ'))
                            ->content(fn (Get $get): string => self::legacyPainVasDisplay($get('pain_vas')))
                            ->columnSpanFull(),
                        Textarea::make('protocol')->label(__('Протокол'))->rows(3)->placeholder(__('Назначенные процедуры и план')),
                        Textarea::make('subjective_feelings')->label(__('Субъективные ощущения клиента после сеанса'))->rows(3)->placeholder(__('Что клиент почувствовал после сеанса')),
                        Textarea::make('visual_posture_diagnostics')->label(__('Визуальная диагностика осанки'))->rows(3)->placeholder(__('Изменения линии плеч, таза и позвоночника')),
                        Textarea::make('result')->label(__('Результат после сеанса'))->rows(3)->placeholder(__('Эффект после процедур/до следующего сеанса')),
                    ])->columnSpanFull(),
            ]);
    }

    private static function scoreDisplay(mixed $value): string
    {
        return is_numeric($value) && (int) $value >= 0 && (int) $value <= 10
            ? __(':value / 10', ['value' => (int) $value])
            : __('Нет данных');
    }

    private static function previousAfterScore(Get $get): mixed
    {
        $state = $get('');

        return is_array($state) ? ($state['previous_after_score'] ?? null) : null;
    }

    private static function legacyPainVasDisplay(mixed $value): string
    {
        return is_numeric($value) && (int) $value >= 0 && (int) $value <= 10
            ? __(':value / 10', ['value' => (int) $value])
            : __('Не указана');
    }

    /** @return array<int, string> */
    private static function rootCauseOptions(Get $get): array
    {
        $causeOptions = app(MedicalClinicalCatalog::class)->causeOptions(app(OrganizationContext::class)->id());
        $testResults = $get('../../test_results');
        $causeIds = collect(is_array($testResults) ? $testResults : [])
            ->flatMap(static fn (mixed $test): array => is_array($test) && is_array($test['cause_ids'] ?? null)
                ? array_map(static fn (mixed $id): int => (int) $id, $test['cause_ids'])
                : [])
            ->filter(static fn (int $id): bool => $id > 0)
            ->unique()
            ->all();
        $currentCauseId = (int) $get('cause_id');
        if ($currentCauseId > 0) {
            $causeIds[] = $currentCauseId;
        }

        return array_intersect_key($causeOptions, array_flip($causeIds));
    }

    private static function parentClient(Select $component): Client
    {
        $livewire = $component->getLivewire();
        abort_unless(method_exists($livewire, 'getParentRecord'), 404);
        $parent = $livewire->getParentRecord();

        abort_unless($parent instanceof Client, 404);

        return $parent;
    }

    /** @return array<int|string, string> */
    private static function specialistResults(string $search): array
    {
        $query = Specialist::query()
            ->where('organization_id', app(OrganizationContext::class)->id())
            ->when(trim($search) !== '', fn (Builder $query) => $query->where('display_name', 'like', '%'.trim($search).'%'))
            ->orderByDesc('is_active')
            ->orderBy('display_name')
            ->limit(50);

        return $query->get(['id', 'display_name', 'is_active'])
            ->mapWithKeys(static fn (Specialist $specialist): array => [
                $specialist->getKey() => self::specialistPresentation($specialist),
            ])
            ->all();
    }

    private static function specialistLabel(mixed $value): ?string
    {
        if (! is_scalar($value) || ! is_numeric($value)) {
            return null;
        }

        $specialist = Specialist::query()
            ->where('organization_id', app(OrganizationContext::class)->id())
            ->whereKey((int) $value)
            ->first();

        return $specialist instanceof Specialist ? self::specialistPresentation($specialist) : null;
    }

    private static function specialistPresentation(Specialist $specialist): string
    {
        return __(':name (:status)', [
            'name' => $specialist->display_name,
            'status' => $specialist->is_active ? __('активен') : __('неактивен'),
        ]);
    }

    /** @return array<int|string, string> */
    private static function bookingResults(string $search, Select $component): array
    {
        $query = self::bookingQuery($component);
        $normalized = trim($search);

        if (ctype_digit($normalized)) {
            $query->whereKey((int) $normalized);
        } elseif ($normalized !== '') {
            try {
                $query->whereDate('starts_at', Carbon::parse($normalized));
            } catch (\Throwable) {
                $query->whereKey(0);
            }
        }

        return $query
            ->orderByDesc('starts_at')
            ->limit(50)
            ->get(['id', 'starts_at'])
            ->mapWithKeys(static fn (Booking $booking): array => [
                $booking->getKey() => Carbon::parse((string) $booking->getAttribute('starts_at'), 'UTC')
                    ->setTimezone(app(OrganizationContext::class)->defaultTimezone())
                    ->format('d.m.Y H:i').' (#'.$booking->getKey().')',
            ])
            ->all();
    }

    private static function bookingLabel(mixed $value, Select $component): ?string
    {
        if (! is_scalar($value) || ! is_numeric($value)) {
            return null;
        }

        $booking = self::bookingQuery($component)
            ->whereKey((int) $value)
            ->first(['id', 'starts_at']);

        return $booking instanceof Booking
            ? Carbon::parse((string) $booking->getAttribute('starts_at'), 'UTC')
                ->setTimezone(app(OrganizationContext::class)->defaultTimezone())
                ->format('d.m.Y H:i').' (#'.$booking->getKey().')'
            : null;
    }

    /** @return Builder<Booking> */
    private static function bookingQuery(Select $component): Builder
    {
        $parent = self::parentClient($component);
        $specialistId = (int) ($component->getLivewire()->data['specialist_id'] ?? 0);

        return Booking::query()
            ->where('organization_id', app(OrganizationContext::class)->id())
            ->where(fn (Builder $query) => $query
                ->whereHas('patientProfile', fn (Builder $patients) => $patients
                    ->where('organization_id', app(OrganizationContext::class)->id())->where('clinical_client_id', $parent->getKey()))
                ->orWhere(fn (Builder $legacy) => $legacy->whereNull('patient_profile_id')->where('client_id', $parent->getKey())))
            ->when($specialistId > 0, fn (Builder $query) => $query->where('specialist_id', $specialistId));
    }
}
