<?php

namespace App\Filament\Resources\Services\Schemas;

use App\Filament\Support\ScheduleImpactPreview;
use App\Modules\Finance\Application\CurrencyConfigurationService;
use App\Modules\Finance\Domain\Services\CurrencyCatalog;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Services\Domain\Enums\CatalogItemType;
use App\Modules\Services\Domain\Models\Service;
use App\Rules\HttpsImageUrl;
use App\Rules\MajorUnitPrice;
use App\Rules\ServicePriceCurrencyPair;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\FileUpload;
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
                Section::make('Основное')
                    ->schema([
                        TextInput::make('name')
                            ->label('Название')
                            ->required()
                            ->maxLength(160),
                        Select::make('catalog_type')
                            ->label('Тип предложения')
                            ->options([
                                CatalogItemType::Service->value => 'Услуга',
                                CatalogItemType::PhysicalProduct->value => 'Физический товар',
                                CatalogItemType::OnlineProduct->value => 'Онлайн-товар',
                            ])
                            ->required()
                            ->default(CatalogItemType::Service->value),
                        TextInput::make('category')
                            ->label('Категория')
                            ->maxLength(120),
                        Toggle::make('is_active')
                            ->label('Доступна для записи')
                            ->required()
                            ->default(true),
                        Textarea::make('summary')
                            ->label('Краткое описание')
                            ->required()
                            ->maxLength(500)
                            ->rows(3)
                            ->columnSpanFull(),
                    ])
                    ->columns(2),

                Section::make('Приём')
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
                    ->columns(2),

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
                        TextInput::make('payment_policy')
                            ->label('Условия оплаты')
                            ->maxLength(64)
                            ->columnSpanFull(),
                    ])
                    ->columns(2),

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
                            ->helperText('HTTPS-ссылка на изображение без загрузки файла.')
                            ->maxLength(2048)
                            ->rules([new HttpsImageUrl])
                            ->columnSpanFull(),
                        Toggle::make('remove_image')
                            ->label('Удалить текущее изображение')
                            ->visible(fn (?Service $record): bool => $record instanceof Service
                                && (self::hasValue($record->getAttribute('image_path'))
                                    || self::hasValue($record->getAttribute('external_image_url')))),
                    ])
                    ->columns(2),

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
}
