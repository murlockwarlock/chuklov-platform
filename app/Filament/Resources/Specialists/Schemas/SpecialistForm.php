<?php

namespace App\Filament\Resources\Specialists\Schemas;

use App\Filament\Support\ScheduleImpactPreview;
use App\Filament\Support\TimezoneOptions;
use App\Models\User;
use App\Modules\Identity\Domain\Models\OrganizationChannelIdentity;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Specialists\Domain\Models\Specialist;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
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
                    ->nullable()
                    ->live()
                    ->afterStateUpdated(function (Set $set, ?string $state): void {
                        $set('telegram_id', $state === null
                            ? null
                            : OrganizationChannelIdentity::query()
                                ->where('organization_id', app(OrganizationContext::class)->id())
                                ->where('user_id', (int) $state)
                                ->where('channel', 'telegram')
                                ->value('external_id'));
                    }),
                TextInput::make('telegram_id')
                    ->label('Telegram ID специалиста')
                    ->maxLength(20)
                    ->nullable()
                    ->regex('/^[0-9]{1,20}$/')
                    ->afterStateHydrated(function (TextInput $component, ?Specialist $record): void {
                        $component->state($record?->telegramNotificationIdentity?->external_id);
                    })
                    ->helperText('Укажите числовой ID чата Telegram. Уведомления будут отправляться этому специалисту.'),
                Toggle::make('is_active')
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
