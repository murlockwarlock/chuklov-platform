<?php

namespace App\Filament\Resources\TrackerPlans\Schemas;

use App\Models\User;
use App\Modules\Finance\Application\CurrencyConfigurationService;
use App\Modules\Finance\Application\FinanceAuthorization;
use App\Modules\Finance\Domain\Services\CurrencyCatalog;
use App\Modules\Organizations\Application\OrganizationContext;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

final class TrackerPlanForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('Основное'))
                ->schema([
                    TextInput::make('name')->label(__('Название'))->required()->maxLength(160),
                    TextInput::make('price')->label(__('Цена'))->required()->inputMode('decimal')->maxLength(32)->regex('/^(?:0|[1-9][0-9]{0,18})(?:\.[0-9]{1,2})?$/'),
                    Select::make('currency')->label(__('Валюта'))->options(fn (): array => self::currencyOptions())->required()->searchable(),
                    TextInput::make('duration_days')
                        ->label(__('Срок доступа (дни)'))
                        ->helperText(__('Для месячного тарифа укажите 30 дней. Продление оформляется отдельной покупкой.'))
                        ->integer()
                        ->minValue(1)
                        ->maxValue(3650)
                        ->default(30)
                        ->required(),
                    Textarea::make('description')->label(__('Короткое описание для клиента'))->maxLength(500)->rows(3)->columnSpanFull(),
                    Textarea::make('monthly_practice')->label(__('Месячная практика или содержание'))->maxLength(5000)->rows(4)->columnSpanFull(),
                ])
                ->columns(2)
                ->columnSpanFull(),
            Section::make(__('Публикация и доступ'))
                ->schema([
                    Toggle::make('is_active')->label(__('Тариф активен'))->default(true)->inline(false),
                    Toggle::make('is_visible')->label(__('Показывать клиентам'))->default(true)->inline(false),
                    Toggle::make('included_access')->label(__('Включает доступ к трекеру'))->default(true)->inline(false),
                    TextInput::make('display_order')->label(__('Порядок отображения'))->integer()->minValue(0)->default(0)->required(),
                ])
                ->columns(2)
                ->columnSpanFull(),
            Section::make(__('Онлайн-оплата'))
                ->visible(fn (): bool => self::canViewFinance())
                ->schema([
                    Toggle::make('lava_enabled')
                        ->label(__('Принимать оплату через Lava'))
                        ->helperText(__('Включайте после сохранения API-ключа Lava в разделе «Финансы».'))
                        ->live()
                        ->default(false)
                        ->disabled(fn (): bool => ! self::canManageFinance()),
                    Placeholder::make('lava_currency')
                        ->label(__('Валюта'))
                        ->content(fn (Get $get): string => self::currencyLabel($get('currency'))),
                    TextInput::make('lava_offer_id')
                        ->label(__('Offer ID в Lava'))
                        ->helperText(__('Скопируйте UUID предложения из кабинета Lava.'))
                        ->maxLength(180)
                        ->uuid()
                        ->required(fn (Get $get): bool => (bool) $get('lava_enabled'))
                        ->visible(fn (Get $get): bool => (bool) $get('lava_enabled'))
                        ->disabled(fn (): bool => ! self::canManageFinance()),
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

    private static function canViewFinance(): bool
    {
        $actor = auth()->user();

        return $actor instanceof User && app(FinanceAuthorization::class)->allowsView($actor);
    }

    private static function canManageFinance(): bool
    {
        $actor = auth()->user();

        return $actor instanceof User && app(FinanceAuthorization::class)->allowsManage($actor);
    }

    private static function currencyLabel(mixed $currency): string
    {
        if (! is_string($currency) || trim($currency) === '') {
            return __('Сначала укажите валюту тарифа.');
        }

        try {
            $code = app(CurrencyCatalog::class)->code($currency);

            return app(CurrencyCatalog::class)->definition($code)->name.' ('.$code->value.')';
        } catch (\InvalidArgumentException) {
            return __('Валюта тарифа указана неверно.');
        }
    }
}
