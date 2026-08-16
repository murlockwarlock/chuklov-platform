<?php

namespace App\Filament\Resources\AiProviders\RelationManagers;

use App\Modules\AI\Domain\Enums\AiCapability;
use App\Modules\AI\Domain\Enums\ModelLifecycleStatus;
use App\Modules\AI\Domain\Models\AiModelConfiguration;
use App\Modules\AI\Domain\Models\AiModelRelease;
use App\Modules\AI\Domain\Models\AiProviderConfiguration;
use App\Modules\AI\Domain\ValueObjects\AiPricingSnapshot;
use App\Modules\Organizations\Application\OrganizationContext;
use BackedEnum;
use Carbon\Carbon;
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
                CheckboxList::make('capabilities')
                    ->label('Поддерживаемые возможности AI')
                    ->options(collect(AiCapability::cases())->mapWithKeys(fn ($c) => [$c->value => $c->label()]))
                    ->columnSpanFull(),
                Toggle::make('is_enabled')
                    ->label('Модель активна')
                    ->default(true),
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
                        $orgId = app(OrganizationContext::class)->id();
                        /** @var AiProviderConfiguration $provider */
                        $provider = $this->getOwnerRecord();

                        $pricing = new AiPricingSnapshot(
                            currency: 'USD',
                            inputCostPerMillionMinorUnits: (int) ($data['input_cost_per_million'] ?? 15),
                            outputCostPerMillionMinorUnits: (int) ($data['output_cost_per_million'] ?? 60),
                        );

                        $model = new AiModelConfiguration([
                            'organization_id' => $orgId,
                            'provider_config_id' => $provider->id,
                            'model_name' => (string) $data['model_name'],
                            'display_name' => (string) $data['display_name'],
                            'is_enabled' => (bool) ($data['is_enabled'] ?? true),
                            'lifecycle_status' => ModelLifecycleStatus::Active,
                            'capabilities' => array_values(array_map('strval', (array) ($data['capabilities'] ?? []))),
                            'pricing_snapshot' => $pricing->toArray(),
                            'failover_priority' => (int) ($data['failover_priority'] ?? 1),
                        ]);
                        $model->save();

                        $release = new AiModelRelease([
                            'organization_id' => $orgId,
                            'model_config_id' => $model->id,
                            'release_number' => 1,
                            'status' => 'active',
                            'provider_name' => $provider->provider_name,
                            'model_name' => $model->model_name,
                            'capabilities' => $model->capabilities,
                            'pricing_snapshot' => $pricing->toArray(),
                            'activated_at' => Carbon::now(),
                            'activated_by_user_id' => Auth::id(),
                        ]);
                        $release->save();

                        $model->update(['active_release_id' => $release->id]);

                        return $model;
                    }),
            ])
            ->recordActions([
                EditAction::make()
                    ->using(function (AiModelConfiguration $record, array $data): AiModelConfiguration {
                        $pricing = new AiPricingSnapshot(
                            currency: 'USD',
                            inputCostPerMillionMinorUnits: (int) ($data['input_cost_per_million'] ?? 15),
                            outputCostPerMillionMinorUnits: (int) ($data['output_cost_per_million'] ?? 60),
                        );

                        $record->update([
                            'model_name' => (string) $data['model_name'],
                            'display_name' => (string) $data['display_name'],
                            'is_enabled' => (bool) ($data['is_enabled'] ?? true),
                            'capabilities' => array_values(array_map('strval', (array) ($data['capabilities'] ?? []))),
                            'pricing_snapshot' => $pricing->toArray(),
                            'failover_priority' => (int) ($data['failover_priority'] ?? 1),
                        ]);

                        return $record;
                    }),
            ]);
    }
}
