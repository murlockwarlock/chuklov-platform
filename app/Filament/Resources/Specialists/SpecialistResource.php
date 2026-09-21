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
use App\Modules\Identity\Domain\Enums\ChannelIdentityStatus;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Specialists\Domain\Models\Specialist;
use BackedEnum;
use Filament\Infolists\Components\TextEntry;
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
                TextEntry::make('display_name')->label(__('Имя специалиста')),
                TextEntry::make('is_active')
                    ->label(__('Доступен'))
                    ->formatStateUsing(fn (bool $state): string => $state ? __('Да') : __('Нет')),
                TextEntry::make('timezone')
                    ->label(__('Часовой пояс специалиста'))
                    ->formatStateUsing(fn (?string $state): string => self::timezoneLabel($state)),
                TextEntry::make('viewer_timezone')
                    ->label(__('Часовой пояс CRM'))
                    ->formatStateUsing(fn (?string $state): string => self::timezoneLabel($state, __('Часовой пояс организации'))),
                TextEntry::make('staffUser.name')
                    ->label(__('Сотрудник CRM'))
                    ->placeholder(__('Не привязан'))
                    ->url(fn (Specialist $record): ?string => $record->staff_user_id === null
                        ? null
                        : CrmEntityLinks::specialistUrl($record))
                    ->color(fn (Specialist $record): ?string => $record->staff_user_id === null
                        ? null
                        : (CrmEntityLinks::specialistUrl($record) === null ? null : 'primary')),
                TextEntry::make('staffUser.email')->label(__('Email сотрудника'))->placeholder(__('Не указан')),
                TextEntry::make('telegramNotificationIdentity.verification_status')
                    ->label('Telegram')
                    ->formatStateUsing(fn (?ChannelIdentityStatus $state): string => $state === ChannelIdentityStatus::Verified
                        ? __('Подключён')
                        : __('Не подключён')),
                TextEntry::make('telegramNotificationIdentity.external_id')
                    ->label(__('Telegram ID специалиста'))
                    ->placeholder(__('Не подключён')),
                TextEntry::make('notifications_enabled')
                    ->label(__('Уведомления специалисту'))
                    ->formatStateUsing(fn (bool $state): string => $state ? __('Включены') : __('Выключены')),
                TextEntry::make('working_hours_count')
                    ->label(__('Рабочих интервалов'))
                    ->formatStateUsing(fn (?int $state): string => $state === null ? __('Нет данных') : (string) $state),
                TextEntry::make('specialist_service_assignments_count')
                    ->label(__('Назначенных услуг'))
                    ->formatStateUsing(fn (?int $state): string => $state === null ? __('Нет данных') : (string) $state),
                TextEntry::make('bookings_count')
                    ->label(__('Всего записей'))
                    ->formatStateUsing(fn (?int $state): string => $state === null ? __('Нет данных') : (string) $state),
                TextEntry::make('created_at')->label(__('Создано'))->dateTime('d.m.Y H:i'),
                TextEntry::make('updated_at')->label(__('Изменено'))->dateTime('d.m.Y H:i'),
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
}
