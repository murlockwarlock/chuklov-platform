<?php

namespace App\Filament\Resources\Specialists;

use App\Filament\Resources\Specialists\Pages\CreateSpecialist;
use App\Filament\Resources\Specialists\Pages\EditSpecialist;
use App\Filament\Resources\Specialists\Pages\ListSpecialists;
use App\Filament\Resources\Specialists\Pages\ViewSpecialist;
use App\Filament\Resources\Specialists\Schemas\SpecialistForm;
use App\Filament\Resources\Specialists\Tables\SpecialistsTable;
use App\Filament\Support\CrmEntityLinks;
use App\Filament\Support\LocalizedResource;
use App\Filament\Support\TimezoneOptions;
use App\Modules\Identity\Application\ProfileReferenceData;
use App\Modules\Identity\Application\VerifiedChannelIdentity;
use App\Modules\Identity\Domain\Enums\ChannelIdentityStatus;
use App\Modules\Identity\Domain\Models\OrganizationChannelIdentity;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Specialists\Application\SpecialistImageUrlResolver;
use App\Modules\Specialists\Domain\Models\Specialist;
use App\Modules\Specialists\Domain\ValueObjects\SpecialistExperience;
use BackedEnum;
use Filament\Infolists\Components\ImageEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class SpecialistResource extends LocalizedResource
{
    protected static ?string $model = Specialist::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedIdentification;

    protected static ?string $navigationLabel = 'Специалисты';

    protected static string|\UnitEnum|null $navigationGroup = 'Команда и услуги';

    protected static ?int $navigationSort = 1;

    protected static ?string $modelLabel = 'специалист';

    protected static ?string $pluralModelLabel = 'специалисты';

    protected static ?string $breadcrumb = 'Специалисты';

    protected static ?string $recordTitleAttribute = 'display_name';

    public static function form(Schema $schema): Schema
    {
        return SpecialistForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('Профиль специалиста'))
                    ->schema([
                        ImageEntry::make('profile_image_path')
                            ->label(__('Фото'))
                            ->getStateUsing(fn (Specialist $record): ?string => app(SpecialistImageUrlResolver::class)->resolve($record))
                            ->imageSize(160)
                            ->circular(),
                        TextEntry::make('display_name')->label(__('Имя и фамилия')),
                        TextEntry::make('specialization')->label(__('Специализация'))->placeholder(__('Не указана')),
                        TextEntry::make('practices_since_year')
                            ->label(__('Опыт работы'))
                            ->getStateUsing(fn (Specialist $record): ?string => SpecialistExperience::fromYear($record->practices_since_year)?->label(app()->getLocale()))
                            ->placeholder(__('Не указан')),
                        TextEntry::make('qualification')->label(__('Квалификация и регалии'))->placeholder(__('Не заполнено'))->columnSpanFull(),
                        TextEntry::make('bio')->label(__('О специалисте'))->placeholder(__('Не заполнено'))->columnSpanFull(),
                        TextEntry::make('country')
                            ->label(__('Страна'))
                            ->getStateUsing(fn (Specialist $record): ?string => app(ProfileReferenceData::class)->countryName((string) $record->country, app()->getLocale())
                                ?? (is_string($record->country) && trim($record->country) !== '' ? $record->country : null))
                            ->placeholder(__('Не указана')),
                        TextEntry::make('city')->label(__('Город'))->placeholder(__('Не указан')),
                    ])
                    ->columns(2),
                Section::make(__('Контакты'))
                    ->schema([
                        TextEntry::make('phone')
                            ->label(__('Телефон'))
                            ->getStateUsing(fn (Specialist $record): ?string => self::contactLabel($record->phone, $record->phone_visible))
                            ->placeholder(__('Не указан')),
                        TextEntry::make('email')
                            ->label(__('Email'))
                            ->getStateUsing(fn (Specialist $record): ?string => self::contactLabel($record->email, $record->email_visible))
                            ->placeholder(__('Не указан')),
                        TextEntry::make('telegram')
                            ->label(__('Telegram'))
                            ->getStateUsing(fn (Specialist $record): ?string => self::contactLabel($record->telegram, $record->telegram_visible))
                            ->placeholder(__('Не указан')),
                        TextEntry::make('id')
                            ->label(__('Служебный Telegram'))
                            ->formatStateUsing(fn (Specialist $record): string => self::telegramStatusLabel($record))
                            ->url(fn (Specialist $record): ?string => ($username = self::verifiedTelegramUsername($record)) === null
                                ? null
                                : 'https://t.me/'.$username),
                    ])
                    ->columns(2),
                Section::make(__('Работа и расписание'))
                    ->schema([
                        TextEntry::make('timezone')
                            ->label(__('Часовой пояс специалиста'))
                            ->formatStateUsing(fn (?string $state): string => self::timezoneLabel($state)),
                        TextEntry::make('viewer_timezone')
                            ->label(__('Часовой пояс CRM'))
                            ->formatStateUsing(fn (?string $state): string => self::timezoneLabel($state, __('Часовой пояс организации'))),
                        TextEntry::make('working_hours_count')
                            ->label(__('Рабочих интервалов'))
                            ->formatStateUsing(fn (?int $state): string => $state === null ? __('Нет данных') : (string) $state),
                        TextEntry::make('specialist_service_assignments_count')
                            ->label(__('Назначенных услуг'))
                            ->formatStateUsing(fn (?int $state): string => $state === null ? __('Нет данных') : (string) $state),
                    ])
                    ->columns(2),
                Section::make(__('Доступ и уведомления'))
                    ->schema([
                        TextEntry::make('is_active')
                            ->label(__('Статус'))
                            ->formatStateUsing(fn (bool $state): string => $state ? __('Доступен для новых записей') : __('Скрыт из новых записей')),
                        TextEntry::make('staffUser.name')
                            ->label(__('Аккаунт CRM'))
                            ->placeholder(__('Не привязан'))
                            ->url(fn (Specialist $record): ?string => $record->staff_user_id === null
                                ? null
                                : CrmEntityLinks::specialistUrl($record))
                            ->color(fn (Specialist $record): ?string => $record->staff_user_id === null
                                ? null
                                : (CrmEntityLinks::specialistUrl($record) === null ? null : 'primary')),
                        TextEntry::make('staffUser.email')->label(__('Email сотрудника'))->placeholder(__('Не указан')),
                        TextEntry::make('notifications_enabled')
                            ->label(__('Уведомления специалисту'))
                            ->formatStateUsing(fn (bool $state): string => $state ? __('Включены') : __('Выключены')),
                        TextEntry::make('bookings_count')
                            ->label(__('Всего записей'))
                            ->formatStateUsing(fn (?int $state): string => $state === null ? __('Нет данных') : (string) $state),
                        TextEntry::make('created_at')->label(__('Создано'))->dateTime('d.m.Y H:i'),
                        TextEntry::make('updated_at')->label(__('Изменено'))->dateTime('d.m.Y H:i'),
                    ])
                    ->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return SpecialistsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('organization_id', app(OrganizationContext::class)->id())
            ->with(['staffUser', 'telegramNotificationIdentity'])
            ->withCount(['workingHours', 'specialistServiceAssignments', 'bookings']);
    }

    private static function timezoneLabel(?string $timezone, string $fallback = 'Часовой пояс организации'): string
    {
        return $timezone === null || $timezone === ''
            ? __($fallback)
            : TimezoneOptions::label($timezone).' ('.$timezone.')';
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSpecialists::route('/'),
            'create' => CreateSpecialist::route('/create'),
            'view' => ViewSpecialist::route('/{record}'),
            'edit' => EditSpecialist::route('/{record}/edit'),
        ];
    }

    private static function telegramStatusLabel(Specialist $record): string
    {
        $identity = self::verifiedTelegramIdentity($record);

        if ($identity === null) {
            return __('Не подключён');
        }

        $username = VerifiedChannelIdentity::normalizeUsername($identity->external_username);

        return __('Подключён').($username === null ? '' : ' · @'.$username);
    }

    private static function verifiedTelegramUsername(Specialist $record): ?string
    {
        return VerifiedChannelIdentity::normalizeUsername(self::verifiedTelegramIdentity($record)?->external_username);
    }

    private static function verifiedTelegramIdentity(Specialist $record): ?OrganizationChannelIdentity
    {
        if ($record->staff_user_id === null) {
            return null;
        }

        return OrganizationChannelIdentity::query()
            ->where('organization_id', $record->organization_id)
            ->where('user_id', $record->staff_user_id)
            ->where('channel', 'telegram')
            ->where('verification_status', ChannelIdentityStatus::Verified->value)
            ->first(['verification_status', 'external_username']);
    }

    private static function contactLabel(?string $value, bool $visible): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        return $value.($visible ? ' · '.__('Показывается клиентам') : ' · '.__('Только CRM'));
    }
}
