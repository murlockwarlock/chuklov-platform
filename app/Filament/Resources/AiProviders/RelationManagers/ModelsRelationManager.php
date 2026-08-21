<?php

namespace App\Filament\Resources\AiProviders\RelationManagers;

use App\Models\User;
use App\Modules\AI\Application\Actions\CreateAndActivateModelRelease;
use App\Modules\AI\Application\Actions\CreateModelConfiguration;
use App\Modules\AI\Domain\Enums\AiCapability;
use App\Modules\AI\Domain\Enums\AiModelModality;
use App\Modules\AI\Domain\Enums\ModelLifecycleStatus;
use App\Modules\AI\Domain\Models\AiModelConfiguration;
use App\Modules\AI\Domain\Models\AiProviderConfiguration;
use App\Modules\AI\Domain\ValueObjects\AiMoney;
use App\Modules\AI\Domain\ValueObjects\AiPricingSnapshot;
use BackedEnum;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

class ModelsRelationManager extends RelationManager
{
    protected static string $relationship = 'models';

    protected static ?string $title = 'Модели провайдера';

    protected static string|BackedEnum|null $icon = Heroicon::OutlinedRectangleStack;

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('model_name')
                    ->label('Идентификатор модели')
                    ->helperText('Техническое имя модели у выбранного провайдера.')
                    ->required()
                    ->maxLength(120),
                TextInput::make('display_name')
                    ->label('Название модели')
                    ->required()
                    ->maxLength(120),
                TextInput::make('failover_priority')
                    ->label('Порядок переключения')
                    ->helperText('Меньшее число пробуется раньше при переключении между моделями.')
                    ->integer()
                    ->default(1)
                    ->minValue(1)
                    ->required(),
                TextInput::make('input_cost_per_million')
                    ->label('Входные токены')
                    ->prefix('$')
                    ->suffix('/ 1M токенов')
                    ->helperText('Например, 2.50 означает $2.50 за 1M входных токенов.')
                    ->inputMode('decimal')
                    ->default('0.00')
                    ->formatStateUsing(fn (mixed $state, ?AiModelConfiguration $record): string => self::moneyState($state, $record, 'input'))
                    ->dehydrateStateUsing(fn (mixed $state): int => AiMoney::minorUnitsFromDecimal($state))
                    ->regex('/^(0|[1-9][0-9]*)(?:\.[0-9]{1,2})?$/')
                    ->required(),
                TextInput::make('output_cost_per_million')
                    ->label('Выходные токены')
                    ->prefix('$')
                    ->suffix('/ 1M токенов')
                    ->helperText('Например, 10.00 означает $10.00 за 1M выходных токенов.')
                    ->inputMode('decimal')
                    ->default('0.00')
                    ->formatStateUsing(fn (mixed $state, ?AiModelConfiguration $record): string => self::moneyState($state, $record, 'output'))
                    ->dehydrateStateUsing(fn (mixed $state): int => AiMoney::minorUnitsFromDecimal($state))
                    ->regex('/^(0|[1-9][0-9]*)(?:\.[0-9]{1,2})?$/')
                    ->required(),
                Toggle::make('is_enabled')
                    ->label('Модель включена')
                    ->default(false),
                Section::make('Расширенные настройки')
                    ->description('Дополнительные тарифы и возможности модели. Они сохраняются в новом выпуске модели.')
                    ->collapsed()
                    ->schema([
                        TextInput::make('cache_read_input_cost_per_million')
                            ->label('Cache-read')
                            ->prefix('$')
                            ->suffix('/ 1M токенов')
                            ->inputMode('decimal')
                            ->default('0.00')
                            ->formatStateUsing(fn (mixed $state, ?AiModelConfiguration $record): string => self::moneyState($state, $record, 'cache_read'))
                            ->dehydrateStateUsing(fn (mixed $state): int => AiMoney::minorUnitsFromDecimal($state))
                            ->regex('/^(0|[1-9][0-9]*)(?:\.[0-9]{1,2})?$/')
                            ->required(),
                        TextInput::make('cache_write_input_cost_per_million')
                            ->label('Cache-write')
                            ->prefix('$')
                            ->suffix('/ 1M токенов')
                            ->inputMode('decimal')
                            ->default('0.00')
                            ->formatStateUsing(fn (mixed $state, ?AiModelConfiguration $record): string => self::moneyState($state, $record, 'cache_write'))
                            ->dehydrateStateUsing(fn (mixed $state): int => AiMoney::minorUnitsFromDecimal($state))
                            ->regex('/^(0|[1-9][0-9]*)(?:\.[0-9]{1,2})?$/')
                            ->required(),
                        TextInput::make('reasoning_cost_per_million')
                            ->label('Reasoning')
                            ->prefix('$')
                            ->suffix('/ 1M токенов')
                            ->inputMode('decimal')
                            ->default('0.00')
                            ->formatStateUsing(fn (mixed $state, ?AiModelConfiguration $record): string => self::moneyState($state, $record, 'reasoning'))
                            ->dehydrateStateUsing(fn (mixed $state): int => AiMoney::minorUnitsFromDecimal($state))
                            ->regex('/^(0|[1-9][0-9]*)(?:\.[0-9]{1,2})?$/')
                            ->required(),
                        Toggle::make('fixed_request_cost_applicable')
                            ->label('Есть фиксированная цена за запрос')
                            ->formatStateUsing(fn (mixed $state, ?AiModelConfiguration $record): bool => $record === null
                                ? (bool) $state
                                : $record->getPricingSnapshot()->fixedRequestCostApplicable)
                            ->default(false),
                        TextInput::make('fixed_request_cost_minor_units')
                            ->label('Фиксированная цена запроса')
                            ->prefix('$')
                            ->inputMode('decimal')
                            ->default('0.00')
                            ->formatStateUsing(fn (mixed $state, ?AiModelConfiguration $record): string => self::moneyState($state, $record, 'fixed'))
                            ->dehydrateStateUsing(fn (mixed $state): int => AiMoney::minorUnitsFromDecimal($state))
                            ->regex('/^(0|[1-9][0-9]*)(?:\.[0-9]{1,2})?$/')
                            ->required(),
                        TextInput::make('unsupported_meters')
                            ->label('Неподдерживаемые биллинговые метры')
                            ->helperText('Через запятую. Версию с неподдерживаемым метром нельзя активировать.')
                            ->placeholder('например: image_input, provider_surcharge')
                            ->formatStateUsing(fn (mixed $state, ?AiModelConfiguration $record): string => $record === null
                                ? (string) ($state ?? '')
                                : implode(', ', $record->getPricingSnapshot()->unsupportedMeters)),
                        CheckboxList::make('capabilities')
                            ->label('Возможности и типы входных данных')
                            ->options([
                                ...collect(AiCapability::cases())->mapWithKeys(fn (AiCapability $capability) => [$capability->value => $capability->label()])->all(),
                                ...collect(AiModelModality::cases())->mapWithKeys(fn (AiModelModality $modality) => [$modality->value => $modality->label()])->all(),
                            ])
                            ->columnSpanFull(),
                    ])
                    ->columns(2)
                    ->columnSpanFull(),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('display_name')->label('Название')->sortable(),
                TextColumn::make('model_name')->label('Идентификатор модели')->searchable(),
                TextColumn::make('failover_priority')->label('Приоритет')->sortable(),
                TextColumn::make('lifecycle_status')
                    ->label('Состояние выпуска')
                    ->formatStateUsing(fn ($state) => $state instanceof ModelLifecycleStatus ? $state->label() : (string) $state),
                TextColumn::make('is_enabled')->label('Статус')->formatStateUsing(fn ($state) => $state ? 'Включена' : 'Отключена'),
                TextColumn::make('pricing_snapshot')->label('Цена ($ / 1M токенов)')->formatStateUsing(function ($state): string {
                    $snap = AiPricingSnapshot::fromArray((array) $state);

                    return '$'.AiMoney::decimalFromMinorUnits($snap->inputCostPerMillionMinorUnits)
                        .' / $'.AiMoney::decimalFromMinorUnits($snap->outputCostPerMillionMinorUnits);
                }),
            ])
            ->headerActions([
                CreateAction::make()
                    ->label('Добавить модель')
                    ->using(function (array $data): AiModelConfiguration {
                        $actor = Auth::user();
                        if (! $actor instanceof User) {
                            throw new \LogicException('Authenticated user required.');
                        }
                        /** @var AiProviderConfiguration $provider */
                        $provider = $this->getOwnerRecord();

                        return app(CreateModelConfiguration::class)->handle($actor, $provider, $data);
                    }),
            ])
            ->recordActions([
                EditAction::make()
                    ->using(function (AiModelConfiguration $record, array $data): AiModelConfiguration {
                        $actor = Auth::user();
                        if (! $actor instanceof User) {
                            throw new \LogicException('Authenticated user required.');
                        }

                        app(CreateAndActivateModelRelease::class)->handle($actor, $record, $data);

                        return $record->fresh() ?? $record;
                    }),
            ]);
    }

    private static function moneyState(mixed $state, ?AiModelConfiguration $record, string $field): string
    {
        if ($record !== null) {
            $pricing = $record->getPricingSnapshot();
            $minorUnits = match ($field) {
                'input' => $pricing->inputCostPerMillionMinorUnits,
                'output' => $pricing->outputCostPerMillionMinorUnits,
                'cache_read' => $pricing->cacheReadInputCostPerMillionMinorUnits ?? 0,
                'cache_write' => $pricing->cacheWriteInputCostPerMillionMinorUnits ?? 0,
                'reasoning' => $pricing->reasoningCostPerMillionMinorUnits ?? 0,
                'fixed' => $pricing->fixedRequestCostMinorUnits ?? 0,
                default => 0,
            };

            return AiMoney::decimalFromMinorUnits($minorUnits);
        }

        return $state === null || $state === '' ? '0.00' : (string) $state;
    }
}
