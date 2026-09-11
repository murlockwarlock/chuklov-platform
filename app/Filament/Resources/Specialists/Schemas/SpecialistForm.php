<?php

namespace App\Filament\Resources\Specialists\Schemas;

use App\Filament\Support\ScheduleImpactPreview;
use App\Filament\Support\TimezoneOptions;
use App\Models\User;
use App\Modules\Identity\Domain\Enums\ChannelIdentityStatus;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Specialists\Domain\Models\Specialist;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class SpecialistForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('display_name')
                    ->label('Имя специалиста')
                    ->required()
                    ->maxLength(160),
                Select::make('timezone')
                    ->label('Часовой пояс специалиста')
                    ->options(fn (Get $get): array => TimezoneOptions::options(
                        current: $get('timezone'),
                        organization: app(OrganizationContext::class)->organization()->defaultTimezone(),
                    ))
                    ->searchable()
                    ->nullable()
                    ->helperText('Если не выбрать, используется часовой пояс организации.'),
                Select::make('viewer_timezone')
                    ->label('Часовой пояс CRM')
                    ->options(fn (Get $get): array => TimezoneOptions::options(
                        current: $get('viewer_timezone'),
                        organization: app(OrganizationContext::class)->organization()->defaultTimezone(),
                    ))
                    ->searchable()
                    ->nullable()
                    ->afterStateHydrated(function (Select $component, ?Specialist $record): void {
                        $component->state($record?->viewer_timezone);
                    })
                    ->helperText('Меняет только отображение времени в CRM и уведомлениях. Уже созданные записи не сдвигаются.'),
                Select::make('staff_user_id')
                    ->label('Сотрудник CRM')
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
                    ->nullable(),
                Placeholder::make('telegram_connection')
                    ->label('Привязка Telegram сотрудника')
                    ->content('После сохранения создайте одноразовую ссылку в карточке специалиста и отправьте её сотруднику. Если нужно сменить аккаунт, используйте действие «Перепривязать Telegram» в карточке специалиста.')
                    ->columnSpanFull(),
                TextInput::make('telegram_id')
                    ->label('Подтверждённый Telegram ID')
                    ->disabled()
                    ->dehydrated(false)
                    ->afterStateHydrated(function (TextInput $component, ?Specialist $record): void {
                        $identity = $record?->telegramNotificationIdentity;
                        $component->state($identity?->verification_status === ChannelIdentityStatus::Verified
                            ? $identity->external_id
                            : null);
                    })
                    ->placeholder('Не подключён')
                    ->helperText('Только для просмотра. Подключение выполняется через подтверждённую ссылку в Telegram.')
                    ->columnSpanFull(),
                Toggle::make('is_active')
                    ->label('Активен')
                    ->required()
                    ->default(true),
                Toggle::make('notifications_enabled')
                    ->label('Уведомления специалисту')
                    ->required()
                    ->default(true)
                    ->helperText('Выключение остановит все автоматические уведомления специалисту.'),
                ...ScheduleImpactPreview::components(),
            ]);
    }
}
