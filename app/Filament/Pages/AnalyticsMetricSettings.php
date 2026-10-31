<?php

namespace App\Filament\Pages;

use App\Filament\Support\LocalizedPage;
use App\Models\User;
use App\Modules\Analytics\Application\AnalyticsMetricConfiguration;
use App\Modules\Analytics\Application\AnalyticsMetricSourceCatalog;
use App\Modules\Analytics\Application\Data\DashboardPeriod;
use App\Modules\Analytics\Domain\Enums\AnalyticsMetricAggregation;
use App\Modules\Analytics\Domain\Enums\AnalyticsMetricCalculation;
use App\Modules\Analytics\Domain\Enums\AnalyticsMetricComparison;
use App\Modules\Analytics\Domain\Enums\AnalyticsMetricNullPolicy;
use App\Modules\Analytics\Domain\Enums\AnalyticsMetricUnit;
use App\Modules\Analytics\Domain\Enums\AnalyticsMetricZeroPolicy;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use LogicException;
use Throwable;
use UnitEnum;

/** @property-read Schema $form */
final class AnalyticsMetricSettings extends LocalizedPage
{
    protected static ?string $title = 'Метрики аналитики';

    protected static ?string $navigationLabel = 'Метрики аналитики';

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBarSquare;

    protected static string|UnitEnum|null $navigationGroup = 'Аналитика';

    protected static ?int $navigationSort = 4;

    protected string $view = 'filament.pages.analytics-metric-settings';

    /** @var array<string, mixed>|null */
    public ?array $data = null;

    public static function canAccess(): bool
    {
        $actor = Auth::user();
        if (! $actor instanceof User) {
            return false;
        }

        try {
            return app(OrganizationAuthorizer::class)->allows(
                $actor,
                app(OrganizationContext::class)->organization(),
                OrganizationPermission::ManageSettings,
            );
        } catch (LogicException) {
            return false;
        }
    }

    public function mount(): void
    {
        $this->form->fill([
            'preview_period' => DashboardPeriod::LastThirtyDays,
            'metrics' => app(AnalyticsMetricConfiguration::class)->formValues(),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        $configuration = app(AnalyticsMetricConfiguration::class);

        return $schema->components([
            Section::make(__('Вычисляемые метрики'))
                ->description(__('Создавайте показатели из разрешённых источников и операций. Метрика меняется здесь и сразу используется в аналитике и Excel.'))
                ->schema([
                    Select::make('preview_period')
                        ->label(__('Период для примера'))
                        ->options(DashboardPeriod::options())
                        ->default(DashboardPeriod::LastThirtyDays)
                        ->live(),
                    Repeater::make('metrics')
                        ->label(__('Метрики'))
                        ->schema([
                            Hidden::make('id')->dehydrated(true),
                            Hidden::make('stable_key')->dehydrated(true),
                            Hidden::make('version')->dehydrated(true),
                            TextInput::make('name')
                                ->label(__('Название'))
                                ->required()
                                ->maxLength(160),
                            Textarea::make('description')
                                ->label(__('Подсказка для команды'))
                                ->rows(2)
                                ->maxLength(500),
                            Select::make('source_a_key')
                                ->label(__('Что считаем'))
                                ->options(AnalyticsMetricSourceCatalog::options())
                                ->searchable()
                                ->preload()
                                ->required()
                                ->live(onBlur: true)
                                ->afterStateUpdated(function (Component $component, Get $get, Set $set, mixed $state): void {
                                    $component->getRootContainer()->clearCachedChildSchemas();
                                    $source = is_string($state) ? $state : '';
                                    $set('aggregation', AnalyticsMetricSourceCatalog::defaultAggregation($source));
                                    $this->syncOutputUnit($get, $set, $source);
                                }),
                            Select::make('aggregation')
                                ->label(__('Агрегация'))
                                ->options(fn (Get $get): array => AnalyticsMetricSourceCatalog::aggregationOptions((string) $get('source_a_key')))
                                ->required()
                                ->default(AnalyticsMetricAggregation::SourceValue->value),
                            Select::make('calculation')
                                ->label(__('Операция'))
                                ->options(self::calculationOptions())
                                ->required()
                                ->default(AnalyticsMetricCalculation::Value->value)
                                ->live(onBlur: true)
                                ->afterStateUpdated(function (Component $component, Get $get, Set $set, mixed $state): void {
                                    $component->getRootContainer()->clearCachedChildSchemas();
                                    $this->syncOutputUnit($get, $set);
                                    if ($state === AnalyticsMetricCalculation::Value->value) {
                                        $set('source_b_key', null);
                                        $set('comparison', AnalyticsMetricComparison::None->value);
                                        $set('comparison_metric_id', null);
                                        $set('target_value', null);
                                    }
                                }),
                            Select::make('source_b_key')
                                ->label(__('Сравнить с'))
                                ->options(AnalyticsMetricSourceCatalog::options())
                                ->searchable()
                                ->preload()
                                ->required(fn (Get $get): bool => $this->needsSourceB($get)),
                            Select::make('comparison')
                                ->label(__('Основа сравнения'))
                                ->options(self::comparisonOptions())
                                ->required()
                                ->default(AnalyticsMetricComparison::None->value)
                                ->live()
                                ->afterStateUpdated(function (Component $component, Set $set, mixed $state): void {
                                    $component->getRootContainer()->clearCachedChildSchemas();
                                    if ($state !== AnalyticsMetricComparison::None->value) {
                                        $set('source_b_key', null);
                                    }
                                    if ($state !== AnalyticsMetricComparison::Metric->value) {
                                        $set('comparison_metric_id', null);
                                    }
                                    if ($state !== AnalyticsMetricComparison::Target->value) {
                                        $set('target_value', null);
                                    }
                                }),
                            Select::make('comparison_metric_id')
                                ->label(__('Метрика для сравнения'))
                                ->options(fn (Get $get): array => $this->comparisonMetricOptions($get('id')))
                                ->required(fn (Get $get): bool => $get('comparison') === AnalyticsMetricComparison::Metric->value)
                                ->searchable(),
                            TextInput::make('target_value')
                                ->label(__('Целевое значение'))
                                ->helperText(__('Заполняется при сравнении с целью. Для денежных источников значение указывается в базовой валюте организации.'))
                                ->required(fn (Get $get): bool => $get('comparison') === AnalyticsMetricComparison::Target->value)
                                ->numeric(),
                            Select::make('null_policy')
                                ->label(__('Если данных нет'))
                                ->options(self::nullPolicyOptions())
                                ->required()
                                ->default(AnalyticsMetricNullPolicy::DoNotCalculate->value),
                            Select::make('zero_policy')
                                ->label(__('Если знаменатель равен 0'))
                                ->options(self::zeroPolicyOptions())
                                ->required()
                                ->default(AnalyticsMetricZeroPolicy::DoNotCalculate->value),
                            Select::make('output_unit')
                                ->label(__('Единица'))
                                ->options(self::unitOptions())
                                ->required()
                                ->default(AnalyticsMetricUnit::Number->value),
                            Select::make('decimal_precision')
                                ->label(__('Округление'))
                                ->options([0 => '0 знаков', 1 => '1 знак', 2 => '2 знака', 3 => '3 знака'])
                                ->required()
                                ->default(2),
                            TextInput::make('sort_order')
                                ->label(__('Порядок'))
                                ->numeric()
                                ->minValue(0)
                                ->default(100),
                            Toggle::make('active')
                                ->label(__('Активна'))
                                ->default(true),
                            Placeholder::make('preview')
                                ->label(__('Пример по выбранному периоду'))
                                ->content(fn (Get $get): string => $this->previewText($configuration, $get))
                                ->columnSpanFull(),
                        ])
                        ->columns(['default' => 1, 'sm' => 2, 'lg' => 3])
                        ->itemLabel(fn (array $state): ?string => is_string($state['name'] ?? null) ? $state['name'] : null)
                        ->defaultItems(0)
                        ->addActionLabel(__('Добавить метрику'))
                        ->reorderable(false)
                        ->columnSpanFull(),
                ])
                ->columns(['default' => 1, 'sm' => 2])
                ->columnSpanFull(),
        ])->statePath('data');
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([$this->getFormContentComponent()]);
    }

    public function getFormContentComponent(): Component
    {
        return Form::make([EmbeddedSchema::make('form')])
            ->id('analytics-metric-settings-form')
            ->livewireSubmitHandler('save')
            ->footer([
                Actions::make([
                    Action::make('save')->label(__('Сохранить метрики'))->submit('save'),
                ]),
            ]);
    }

    public function save(): void
    {
        $actor = Auth::user();
        abort_unless($actor instanceof User, 403);

        try {
            app(AnalyticsMetricConfiguration::class)->save($actor, $this->form->getState());
            $this->mount();
            Notification::make()->success()->title(__('Метрики сохранены'))->send();
        } catch (ValidationException $exception) {
            $message = collect($exception->errors())->flatten()->first();
            Notification::make()->danger()->title(is_string($message) ? $message : __('Проверьте настройки метрик.'))->send();
        }
    }

    private function previewText(AnalyticsMetricConfiguration $configuration, Get $get): string
    {
        $actor = Auth::user();
        if (! $actor instanceof User) {
            return __('Недостаточно данных');
        }

        $data = [
            'id' => $get('id'),
            'name' => $get('name'),
            'description' => $get('description'),
            'active' => $get('active'),
            'output_unit' => $get('output_unit'),
            'decimal_precision' => $get('decimal_precision'),
            'source_a_key' => $get('source_a_key'),
            'source_b_key' => $get('source_b_key'),
            'aggregation' => $get('aggregation'),
            'calculation' => $get('calculation'),
            'comparison' => $get('comparison'),
            'comparison_metric_id' => $get('comparison_metric_id'),
            'target_value' => $get('target_value'),
            'null_policy' => $get('null_policy'),
            'zero_policy' => $get('zero_policy'),
            'sort_order' => $get('sort_order'),
        ];

        try {
            $period = DashboardPeriod::fromFilters(
                ['period' => $this->data['preview_period'] ?? DashboardPeriod::LastThirtyDays],
                app(OrganizationContext::class)->organization()->defaultTimezone(),
            );

            return $configuration->preview($actor, $data, $period)->formatted();
        } catch (Throwable) {
            return __('Заполните обязательные поля');
        }
    }

    private function needsSourceB(Get $get): bool
    {
        return $get('comparison') === AnalyticsMetricComparison::None->value
            && ($calculation = AnalyticsMetricCalculation::tryFrom((string) $get('calculation'))) !== null
            && $calculation->requiresSecondValue();
    }

    private function syncOutputUnit(Get $get, Set $set, ?string $source = null): void
    {
        $source ??= is_string($get('source_a_key')) ? $get('source_a_key') : null;
        $calculation = AnalyticsMetricCalculation::tryFrom((string) $get('calculation'));
        if ($source === null || $calculation === null) {
            return;
        }

        $unit = AnalyticsMetricSourceCatalog::outputUnit($source, $calculation);
        if ($unit !== null) {
            $set('output_unit', $unit->value);
        }
    }

    /** @return array<string, string> */
    private function comparisonMetricOptions(mixed $currentId): array
    {
        $currentId = filter_var($currentId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $metrics = app(AnalyticsMetricConfiguration::class)->metrics(true);

        return $metrics
            ->reject(fn ($metric): bool => $currentId !== false && (int) $metric->getKey() === (int) $currentId)
            ->mapWithKeys(fn ($metric): array => [(string) $metric->getKey() => $metric->name])
            ->all();
    }

    /** @return array<string, string> */
    private static function calculationOptions(): array
    {
        $options = [];
        foreach (AnalyticsMetricCalculation::cases() as $calculation) {
            $options[$calculation->value] = $calculation->label();
        }

        return $options;
    }

    /** @return array<string, string> */
    private static function comparisonOptions(): array
    {
        $options = [];
        foreach (AnalyticsMetricComparison::cases() as $comparison) {
            $options[$comparison->value] = $comparison->label();
        }

        return $options;
    }

    /** @return array<string, string> */
    private static function nullPolicyOptions(): array
    {
        $options = [];
        foreach (AnalyticsMetricNullPolicy::cases() as $policy) {
            $options[$policy->value] = $policy->label();
        }

        return $options;
    }

    /** @return array<string, string> */
    private static function zeroPolicyOptions(): array
    {
        $options = [];
        foreach (AnalyticsMetricZeroPolicy::cases() as $policy) {
            $options[$policy->value] = $policy->label();
        }

        return $options;
    }

    /** @return array<string, string> */
    private static function unitOptions(): array
    {
        $options = [];
        foreach (AnalyticsMetricUnit::cases() as $unit) {
            $options[$unit->value] = $unit->label();
        }

        return $options;
    }
}
