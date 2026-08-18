<?php

namespace App\Filament\Resources\AiProviders\RelationManagers;

use App\Models\User;
use App\Modules\AI\Application\Actions\CreateAndActivateModelRelease;
use App\Modules\AI\Application\Actions\CreateModelConfiguration;
use App\Modules\AI\Domain\Enums\AiCapability;
use App\Modules\AI\Domain\Enums\AiModelModality;
use App\Modules\AI\Domain\Models\AiModelConfiguration;
use App\Modules\AI\Domain\Models\AiProviderConfiguration;
use App\Modules\AI\Domain\ValueObjects\AiPricingSnapshot;
use BackedEnum;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
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
                    ->label('Имя модели (gpt-4o, claude-3-5-sonnet и т.д.)')
                    ->required()
                    ->maxLength(120),
                TextInput::make('display_name')
                    ->label('Отображаемое имя')
                    ->required()
                    ->maxLength(120),
                TextInput::make('failover_priority')
                    ->label('Приоритет в цепочке переключения (1 - высший)')
                    ->numeric()
                    ->default(1)
                    ->required(),
                TextInput::make('input_cost_per_million')
                    ->label('Цена за 1M входных токенов (в центах/minor units, например 15 = $0.15)')
                    ->numeric()
                    ->default(15)
                    ->required(),
                TextInput::make('output_cost_per_million')
                    ->label('Цена за 1M выходных токенов (в центах/minor units, например 60 = $0.60)')
                    ->numeric()
                    ->default(60)
                    ->required(),
                TextInput::make('cache_read_input_cost_per_million')
                    ->label('Цена cache-read за 1M токенов')
                    ->numeric()
                    ->default(0)
                    ->required(),
                TextInput::make('cache_write_input_cost_per_million')
                    ->label('Цена cache-write за 1M токенов')
                    ->numeric()
                    ->default(0)
                    ->required(),
                TextInput::make('reasoning_cost_per_million')
                    ->label('Цена reasoning за 1M токенов')
                    ->numeric()
                    ->default(0)
                    ->required(),
                Toggle::make('fixed_request_cost_applicable')
                    ->label('Есть фиксированная цена за запрос')
                    ->default(false),
                TextInput::make('fixed_request_cost_minor_units')
                    ->label('Фиксированная цена запроса (minor units)')
                    ->numeric()
                    ->default(0)
                    ->required(),
                TextInput::make('unsupported_meters')
                    ->label('Неподдерживаемые биллинговые метры')
                    ->helperText('Перечислите через запятую: активация такой версии будет запрещена.')
                    ->placeholder('например: image_input, provider_surcharge'),
                CheckboxList::make('capabilities')
                    ->label('Поддерживаемые возможности AI')
                    ->options([
                        ...collect(AiCapability::cases())->mapWithKeys(fn (AiCapability $capability) => [$capability->value => $capability->label()])->all(),
                        ...collect(AiModelModality::cases())->mapWithKeys(fn (AiModelModality $modality) => [$modality->value => $modality->label()])->all(),
                    ])
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
                TextColumn::make('is_enabled')->label('Статус')->formatStateUsing(fn ($state) => $state ? 'Активна' : 'Отключена'),
                TextColumn::make('pricing_snapshot')->label('Цены ($ / 1M токенов)')->formatStateUsing(function ($state): string {
                    $snap = AiPricingSnapshot::fromArray((array) $state);

                    return '$'.number_format($snap->inputCostPerMillionMinorUnits / 100, 2).' / $'.number_format($snap->outputCostPerMillionMinorUnits / 100, 2);
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
}
