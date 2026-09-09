<?php

namespace App\Filament\Resources\TrackerPlans\Schemas;

use App\Modules\Finance\Application\CurrencyConfigurationService;
use App\Modules\Finance\Domain\Services\CurrencyCatalog;
use App\Modules\Organizations\Application\OrganizationContext;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

final class TrackerPlanForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Тариф')
                ->schema([
                    TextInput::make('name')->label('Название')->required()->maxLength(160),
                    Toggle::make('is_active')->label('Тариф активен')->default(true)->inline(),
                    Toggle::make('is_visible')->label('Показывать клиентам')->default(true)->inline(),
                    TextInput::make('price')->label('Цена')->required()->inputMode('decimal')->maxLength(32)->regex('/^(?:0|[1-9][0-9]{0,18})(?:\.[0-9]{1,2})?$/'),
                    Select::make('currency')->label('Валюта')->options(fn (): array => self::currencyOptions())->required()->searchable(),
                    TextInput::make('duration_days')->label('Срок доступа (дни)')->integer()->minValue(1)->maxValue(3650)->required(),
                    Textarea::make('description')->label('Короткое описание для клиента')->maxLength(500)->rows(3),
                    Toggle::make('included_access')->label('Включает доступ к трекеру')->default(true)->inline(),
                    TextInput::make('display_order')->label('Порядок отображения')->integer()->minValue(0)->default(0)->required(),
                ])
                ->columns(2)
                ->columnSpanFull(),
        ]);
    }

    /** @return array<string, string> */
    private static function currencyOptions(): array
    {
        $catalog = app(CurrencyCatalog::class);
        try {
            $allowed = app(CurrencyConfigurationService::class)->allowedCurrencies(app(OrganizationContext::class)->id());
            if ($allowed !== []) {
                return collect($allowed)->mapWithKeys(fn ($currency): array => [$currency->value => $catalog->definition($currency)->name])->all();
            }
        } catch (\Throwable) {
        }

        return $catalog->options();
    }
}
