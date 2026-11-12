<?php

namespace App\Filament\Resources\Specialists\Schemas;

use App\Filament\Support\ScheduleImpactPreview;
use App\Filament\Support\TimezoneOptions;
use App\Models\User;
use App\Modules\Identity\Application\ProfileReferenceData;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Specialists\Application\SpecialistImageUrlResolver;
use App\Modules\Specialists\Domain\Models\Specialist;
use App\Support\Images\SupportedImageTypes;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Image as SchemaImage;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class SpecialistForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('Основное'))
                    ->schema([
                        FileUpload::make('profile_image')
                            ->label(__('Фото специалиста'))
                            ->image()
                            ->acceptedFileTypes(SupportedImageTypes::acceptedMimeTypes())
                            ->mimeTypeMap(SupportedImageTypes::mimeTypeMap())
                            ->extraInputAttributes(['accept' => SupportedImageTypes::browserAccept()])
                            ->maxSize(self::imageUploadKilobytes())
                            ->storeFiles(false)
                            ->validationMessages([
                                'mimetypes' => __('Не удалось прочитать фотографию. Попробуйте другой файл.'),
                                'max' => __('Фотография должна быть размером до 15 МБ.'),
                            ])
                            ->helperText(__('Загрузите фотографию до 15 МБ. Поддерживаются JPEG, PNG, WebP, HEIC и HEIF.'))
                            ->columnSpanFull(),
                        SchemaImage::make(
                            fn (?Specialist $record): string => self::imagePreviewUrl($record) ?? '',
                            fn (?Specialist $record): string => self::imageAlt($record),
                        )
                            ->imageHeight('16rem')
                            ->imageWidth('16rem')
                            ->extraAttributes(['class' => 'max-w-full rounded-xl object-cover'])
                            ->visible(fn (?Specialist $record, Get $get): bool => self::imagePreviewUrl($record) !== null && ! (bool) $get('remove_profile_image'))
                            ->columnSpanFull(),
                        Placeholder::make('current_image_status')
                            ->label(__('Текущее фото'))
                            ->content(fn (?Specialist $record): string => self::imageStatus($record))
                            ->visible(fn (?Specialist $record, Get $get): bool => self::hasImage($record) && ! (bool) $get('remove_profile_image'))
                            ->columnSpanFull(),
                        Toggle::make('remove_profile_image')
                            ->label(__('Удалить текущее фото'))
                            ->visible(fn (?Specialist $record): bool => self::hasImage($record)),
                        TextInput::make('display_name')
                            ->label(__('Имя и фамилия'))
                            ->required()
                            ->maxLength(160)
                            ->validationMessages([
                                'required' => __('Укажите имя и фамилию специалиста.'),
                                'max' => __('Имя и фамилия должны быть не длиннее 160 символов.'),
                            ]),
                        TextInput::make('specialization')
                            ->label(__('Специализация'))
                            ->maxLength(160)
                            ->validationMessages([
                                'max' => __('Специализация должна быть не длиннее 160 символов.'),
                            ])
                            ->placeholder(__('Например, реабилитолог')),
                        TextInput::make('practices_since_year')
                            ->label(__('Практикует с'))
                            ->integer()
                            ->minValue(1900)
                            ->maxValue((int) now()->year)
                            ->validationMessages([
                                'integer' => __('Укажите год целым числом.'),
                                'min' => __('Год начала практики должен быть не раньше 1900 года.'),
                                'max' => __('Год начала практики не может быть в будущем.'),
                            ])
                            ->placeholder((string) now()->year)
                            ->helperText(__('Укажите год начала практики. Опыт для клиента посчитается автоматически.')),
                        Select::make('country')
                            ->label(__('Страна'))
                            ->options(fn (): array => app(ProfileReferenceData::class)->countryCodeOptions(app()->getLocale()))
                            ->searchable()
                            ->nullable()
                            ->afterStateHydrated(function (Select $component, mixed $state): void {
                                if (is_string($state)) {
                                    $component->state(app(ProfileReferenceData::class)->countryCode($state) ?? $state);
                                }
                            })
                            ->placeholder(__('Выберите страну')),
                        TextInput::make('city')
                            ->label(__('Город'))
                            ->maxLength(100)
                            ->validationMessages([
                                'max' => __('Название города должно быть не длиннее 100 символов.'),
                            ])
                            ->placeholder(__('Например, Алматы')),
                    ])
                    ->columns(2)
                    ->columnSpanFull(),

                Section::make(__('Для клиентов'))
                    ->description(__('Эти данные можно показать клиентам в карточке специалиста. Пустые поля не отображаются.'))
                    ->schema([
                        Textarea::make('qualification')
                            ->label(__('Квалификация и регалии'))
                            ->maxLength(2000)
                            ->rows(4)
                            ->validationMessages([
                                'max' => __('Квалификация и регалии должны быть не длиннее 2000 символов.'),
                            ])
                            ->helperText(__('Коротко укажите образование, квалификацию, сертификаты или профессиональные достижения, которые важно показать клиенту.'))
                            ->columnSpanFull(),
                        Textarea::make('bio')
                            ->label(__('О специалисте'))
                            ->maxLength(10000)
                            ->rows(8)
                            ->validationMessages([
                                'max' => __('Описание должно быть не длиннее 10000 символов.'),
                            ])
                            ->helperText(__('Описание опыта, подхода и работы специалиста для клиентов.'))
                            ->columnSpanFull(),
                    ])
                    ->columns(2)
                    ->columnSpanFull(),

                Section::make(__('Контакты'))
                    ->description(__('Рабочие контакты специалиста. Они не публикуются клиентам автоматически.'))
                    ->schema([
                        TextInput::make('phone')
                            ->label(__('Телефон'))
                            ->tel()
                            ->maxLength(32)
                            ->validationMessages([
                                'regex' => __('Введите корректный номер телефона с кодом страны.'),
                                'max' => __('Телефон должен быть не длиннее 32 символов.'),
                            ])
                            ->placeholder(__('С кодом страны')),
                        Toggle::make('phone_visible')
                            ->label(__('Показывать клиентам'))
                            ->default(false),
                        TextInput::make('email')
                            ->label(__('Email'))
                            ->email()
                            ->maxLength(320)
                            ->validationMessages([
                                'email' => __('Введите корректный email.'),
                                'max' => __('Email должен быть не длиннее 320 символов.'),
                            ]),
                        Toggle::make('email_visible')
                            ->label(__('Показывать клиентам'))
                            ->default(false),
                        TextInput::make('telegram')
                            ->label(__('Telegram'))
                            ->maxLength(255)
                            ->validationMessages([
                                'max' => __('Telegram должен быть не длиннее 255 символов.'),
                            ])
                            ->placeholder(__('@username или https://t.me/username'))
                            ->helperText(__('Укажите username или ссылку на профиль, а не служебный Telegram ID.')),
                        Toggle::make('telegram_visible')
                            ->label(__('Показывать клиентам'))
                            ->default(false),
                    ])
                    ->columns(2)
                    ->columnSpanFull(),

                Section::make(__('Работа и расписание'))
                    ->schema([
                        Select::make('timezone')
                            ->label(__('Часовой пояс специалиста'))
                            ->options(fn (Get $get): array => TimezoneOptions::options(
                                current: $get('timezone'),
                                organization: app(OrganizationContext::class)->organization()->defaultTimezone(),
                            ))
                            ->searchable()
                            ->nullable()
                            ->helperText(__('Если не выбрать, используется часовой пояс организации.')),
                        Select::make('viewer_timezone')
                            ->label(__('Часовой пояс CRM'))
                            ->options(fn (Get $get): array => TimezoneOptions::options(
                                current: $get('viewer_timezone'),
                                organization: app(OrganizationContext::class)->organization()->defaultTimezone(),
                            ))
                            ->searchable()
                            ->nullable()
                            ->afterStateHydrated(function (Select $component, ?Specialist $record): void {
                                $component->state($record?->viewer_timezone);
                            })
                            ->helperText(__('Меняет только отображение времени в CRM и уведомлениях. Уже созданные записи не сдвигаются.')),
                    ])
                    ->columns(2)
                    ->columnSpanFull(),

                Section::make(__('Доступ и уведомления'))
                    ->schema([
                        Select::make('staff_user_id')
                            ->label(__('Аккаунт CRM'))
                            ->options(fn (): array => User::query()
                                ->whereHas('memberships', function ($query): void {
                                    $query
                                        ->where('organization_id', app(OrganizationContext::class)->id())
                                        ->where('is_active', true);
                                })
                                ->orderBy('name')
                                ->pluck('name', 'id')
                                ->all())
                            ->searchable()
                            ->nullable()
                            ->placeholder(__('Не привязан'))
                            ->helperText(__('Внутренний аккаунт CRM для служебной работы. Его контакты не становятся публичными.')),
                        Toggle::make('notifications_enabled')
                            ->label(__('Уведомления специалисту'))
                            ->required()
                            ->default(true)
                            ->helperText(__('Выключение остановит автоматические уведомления специалисту.')),
                        Toggle::make('is_active')
                            ->label(__('Активен'))
                            ->required()
                            ->default(true)
                            ->helperText(__('Неактивный специалист скрыт из новых записей. История записей сохраняется.')),
                    ])
                    ->columns(2)
                    ->columnSpanFull(),

                ...ScheduleImpactPreview::components(),
            ]);
    }

    private static function imageUploadKilobytes(): int
    {
        $bytes = max(1, (int) config('specialist_media.max_bytes', 15_728_640));

        return intdiv($bytes + 1023, 1024);
    }

    private static function hasImage(?Specialist $record): bool
    {
        return $record instanceof Specialist && is_string($record->profile_image_path) && trim($record->profile_image_path) !== '';
    }

    private static function imagePreviewUrl(?Specialist $record): ?string
    {
        return $record instanceof Specialist ? app(SpecialistImageUrlResolver::class)->resolve($record) : null;
    }

    private static function imageAlt(?Specialist $record): string
    {
        return $record instanceof Specialist && trim($record->display_name) !== ''
            ? $record->display_name
            : __('Фото специалиста');
    }

    private static function imageStatus(?Specialist $record): string
    {
        return $record instanceof Specialist && self::imagePreviewUrl($record) !== null
            ? __('Новое фото заменит текущее после сохранения.')
            : __('Фото специалиста не добавлено.');
    }
}
