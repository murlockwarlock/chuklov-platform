<?php

namespace App\Filament\Resources\Services\Schemas;

use App\Filament\Support\ScheduleImpactPreview;
use App\Models\User;
use App\Modules\Finance\Application\CurrencyConfigurationService;
use App\Modules\Finance\Application\FinanceAuthorization;
use App\Modules\Finance\Domain\Services\CurrencyCatalog;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Services\Domain\Enums\CatalogItemType;
use App\Modules\Services\Domain\Enums\ServicePaymentRequirement;
use App\Modules\Services\Domain\Models\Service;
use App\Rules\HttpsImageUrl;
use App\Rules\MajorUnitPrice;
use App\Rules\ServicePriceCurrencyPair;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class ServiceForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Hidden::make('expected_snapshot')->dehydrated()->nullable()->string(),
                Section::make('Основное')
                    ->schema([
                        TextInput::make('name')
                            ->label('Название')
                            ->required()
                            ->maxLength(160),
                        Select::make('catalog_type')
                            ->label('Тип предложения')
                            ->options(fn (?Service $record): array => self::catalogTypeOptions($record))
                            ->required()
                            ->default(CatalogItemType::Service->value)
                            ->live(),
                        TextInput::make('category')
                            ->label('Категория')
                            ->maxLength(120),
                        Toggle::make('is_active')
                            ->label(fn (Get $get): string => self::activeLabel($get('catalog_type')))
                            ->required()
                            ->default(true),
                        Textarea::make('summary')
                            ->label('Краткое описание')
                            ->required()
                            ->maxLength(500)
                            ->rows(3)
                            ->columnSpanFull(),
                    ])
                    ->columns(2)
                    ->columnSpanFull(),

                Section::make('Приём')
                    ->visible(fn (Get $get): bool => self::isCatalogType($get('catalog_type'), CatalogItemType::Service))
                    ->dehydratedWhenHidden(fn (?Service $record): bool => $record instanceof Service)
                    ->schema([
                        TextInput::make('duration_minutes')
                            ->label('Длительность (минуты)')
                            ->integer()
                            ->minValue(1)
                            ->maxValue(65535),
                        TextInput::make('buffer_minutes')
                            ->label('Пауза после визита (минуты)')
                            ->integer()
                            ->default(0)
                            ->minValue(0)
                            ->maxValue(65535),
                        CheckboxList::make('formats')
                            ->options([
                                'office' => 'В клинике',
                                'home' => 'Выезд на дом',
                                'online' => 'Онлайн',
                            ])
                            ->label('Доступные форматы визита')
                            ->columns(3)
                            ->columnSpanFull(),
                    ])
                    ->columns(2)
                    ->columnSpanFull(),

                Section::make('Цена')
                    ->schema([
                        TextInput::make('price')
                            ->label('Цена')
                            ->placeholder('15000 или 15000.50')
                            ->inputMode('decimal')
                            ->maxLength(32)
                            ->rules(fn (Get $get): array => [
                                new MajorUnitPrice(self::nullableString($get('price_currency'))),
                            ]),
                        Select::make('price_currency')
                            ->label('Валюта')
                            ->options(fn (): array => self::priceCurrencyOptions())
                            ->searchable()
                            ->live()
                            ->rules(fn (Get $get): array => [
                                new ServicePriceCurrencyPair($get('price')),
                            ]),
                        KeyValue::make('price_matrix')
                            ->label('Фиксированные цены по валютам')
                            ->helperText('Необязательно. Если цена для валюты не указана, используется настроенный курс.')
                            ->keyLabel('Валюта')
                            ->valueLabel('Цена')
                            ->keyPlaceholder('RUB')
                            ->valuePlaceholder('10000.00')
                            ->columnSpanFull(),
                        Select::make('payment_requirement')
                            ->label('Когда клиент оплачивает')
                            ->options([
                                ServicePaymentRequirement::Postpay->value => 'После сеанса',
                                ServicePaymentRequirement::PrepayFull->value => 'Полная предоплата',
                            ])
                            ->visible(fn (Get $get): bool => self::isCatalogType($get('catalog_type'), CatalogItemType::Service))
                            ->required(fn (Get $get): bool => self::isCatalogType($get('catalog_type'), CatalogItemType::Service))
                            ->default(ServicePaymentRequirement::Postpay->value),
                    ])
                    ->columns(2)
                    ->columnSpanFull(),

                Section::make('Онлайн-оплата')
                    ->visible(fn (): bool => self::canViewFinance())
                    ->schema([
                        Toggle::make('lava_enabled')
                            ->label('Принимать оплату через Lava')
                            ->helperText('Включайте после сохранения API-ключа Lava в разделе «Финансы».')
                            ->live()
                            ->default(false)
                            ->disabled(fn (): bool => ! self::canManageFinance()),
                        Placeholder::make('lava_currency')
                            ->label('Валюта')
                            ->content(fn (Get $get): string => self::currencyLabel($get('price_currency'))),
                        TextInput::make('lava_offer_id')
                            ->label('Offer ID в Lava')
                            ->helperText(fn (Get $get): string => self::lavaOfferHelperText($get('catalog_type')))
                            ->maxLength(180)
                            ->uuid()
                            ->required(fn (Get $get): bool => (bool) $get('lava_enabled'))
                            ->visible(fn (Get $get): bool => (bool) $get('lava_enabled'))
                            ->disabled(fn (): bool => ! self::canManageFinance()),
                        Repeater::make('lava_offers')
                            ->label('Дополнительные предложения Lava')
                            ->helperText('Добавьте отдельный Offer ID для каждой дополнительной валюты из фиксированной матрицы цены.')
                            ->schema([
                                Select::make('currency')
                                    ->label('Валюта')
                                    ->options(fn (): array => self::priceCurrencyOptions())
                                    ->required()
                                    ->searchable(),
                                TextInput::make('offer_id')
                                    ->label('Offer ID в Lava')
                                    ->uuid()
                                    ->required(),
                            ])
                            ->columns(2)
                            ->defaultItems(0)
                            ->addActionLabel('Добавить валюту')
                            ->visible(fn (Get $get): bool => (bool) $get('lava_enabled'))
                            ->disabled(fn (): bool => ! self::canManageFinance())
                            ->columnSpanFull(),
                    ])
                    ->columns(2)
                    ->columnSpanFull(),

                Section::make('Изображение')
                    ->schema([
                        FileUpload::make('service_image')
                            ->label('Загрузить изображение')
                            ->image()
                            ->acceptedFileTypes(['image/jpeg', 'image/png'])
                            ->maxSize(self::imageUploadKilobytes())
                            ->storeFiles(false)
                            ->validationMessages([
                                'mimetypes' => 'Поддерживаются только изображения JPG и PNG.',
                                'max' => 'Изображение должно быть размером до 5 МБ.',
                            ])
                            ->helperText('JPG или PNG размером до 5 МБ.')
                            ->columnSpanFull(),
                        TextInput::make('external_image_url')
                            ->label('Ссылка на изображение')
                            ->helperText('Укажите ссылку на изображение, если не загружаете файл.')
                            ->maxLength(2048)
                            ->rules([new HttpsImageUrl])
                            ->columnSpanFull(),
                        Toggle::make('remove_image')
                            ->label('Удалить текущее изображение')
                            ->visible(fn (?Service $record): bool => $record instanceof Service
                                && (self::hasValue($record->getAttribute('image_path'))
                                    || self::hasValue($record->getAttribute('external_image_url')))),
                    ])
                    ->columns(2)
                    ->columnSpanFull(),

                Section::make('Дополнительно / локализация')
                    ->schema([
                        TextInput::make('name_ru')
                            ->label('Название на русском')
                            ->maxLength(160),
                        TextInput::make('name_en')
                            ->label('Название на английском')
                            ->maxLength(160),
                        Textarea::make('description_ru')
                            ->label('Полное описание на русском')
                            ->maxLength(10000)
                            ->rows(3),
                        Textarea::make('description_en')
                            ->label('Полное описание на английском')
                            ->maxLength(10000)
                            ->rows(3),
                    ])
                    ->columns(2)
                    ->columnSpanFull()
                    ->collapsed(),

                ...ScheduleImpactPreview::components(),
            ]);
    }

    /** @return array<string, string> */
    private static function priceCurrencyOptions(): array
    {
        $catalog = app(CurrencyCatalog::class);

        try {
            $allowed = app(CurrencyConfigurationService::class)->allowedCurrencies(app(OrganizationContext::class)->id());

            if ($allowed !== []) {
                return collect($allowed)->mapWithKeys(fn ($currency): array => [
                    $currency->value => $catalog->definition($currency)->name,
                ])->all();
            }
        } catch (\Throwable) {
        }

        return $catalog->options();
    }

    /** @return array<string, string> */
    private static function catalogTypeOptions(?Service $record): array
    {
        $options = [
            CatalogItemType::Service->value => 'Услуга',
            CatalogItemType::PhysicalProduct->value => 'Физический товар',
            CatalogItemType::OnlineProduct->value => 'Онлайн-товар',
        ];

        return $options;
    }

    private static function isCatalogType(mixed $value, CatalogItemType $expected): bool
    {
        if ($value instanceof CatalogItemType) {
            return $value === $expected;
        }

        return is_string($value) && CatalogItemType::tryFrom($value) === $expected;
    }

    private static function activeLabel(mixed $catalogType): string
    {
        return match (self::catalogType($catalogType)) {
            CatalogItemType::OnlineProduct, CatalogItemType::PhysicalProduct => 'Показывать клиентам',
            default => 'Доступна для записи',
        };
    }

    private static function catalogType(mixed $value): ?CatalogItemType
    {
        if ($value instanceof CatalogItemType) {
            return $value;
        }

        return is_string($value) ? CatalogItemType::tryFrom($value) : null;
    }

    private static function lavaOfferHelperText(mixed $catalogType): string
    {
        $subject = self::isCatalogType($catalogType, CatalogItemType::OnlineProduct)
            ? 'этот товар'
            : (self::isCatalogType($catalogType, CatalogItemType::Service) ? 'эту услугу' : 'это предложение');

        return 'ID предложения из кабинета Lava. Он связывает '.$subject.' с предложением, созданным в Lava.';
    }

    private static function imageUploadKilobytes(): int
    {
        $bytes = max(1, (int) config('service_media.max_bytes', 5_242_880));

        return intdiv($bytes + 1023, 1024);
    }

    private static function hasValue(mixed $value): bool
    {
        return is_string($value) && trim($value) !== '';
    }

    private static function nullableString(mixed $value): ?string
    {
        return self::hasValue($value) ? trim((string) $value) : null;
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
            return 'Сначала укажите валюту цены.';
        }

        try {
            $code = app(CurrencyCatalog::class)->code($currency);

            return app(CurrencyCatalog::class)->definition($code)->name.' ('.$code->value.')';
        } catch (\InvalidArgumentException) {
            return 'Валюта цены указана неверно.';
        }
    }
}
