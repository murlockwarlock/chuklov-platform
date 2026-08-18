<?php

namespace App\Filament\Resources\Clients\Schemas;

use App\Filament\Support\TimezoneOptions;
use App\Models\User;
use App\Modules\Finance\Application\FinanceAuthorization;
use App\Modules\Finance\Application\GetClientBalanceSummary;
use App\Modules\Finance\Domain\ValueObjects\Money;
use App\Modules\Identity\Application\GetClientCommunicationStatus;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\MedicalProfiles\Application\GetMedicalProfile;
use Filament\Infolists\Components\KeyValueEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

final class ClientWorkspaceInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Клиент')
                    ->schema([
                        TextEntry::make('full_name')->label('Имя и фамилия')->weight('bold'),
                        TextEntry::make('id')
                            ->label('ID клиента')
                            ->formatStateUsing(fn (int|string $state): string => '#'.$state),
                        TextEntry::make('email')->label('Email')->placeholder('Не указан'),
                        TextEntry::make('phone')->label('Телефон')->placeholder('Не указан'),
                        TextEntry::make('language')
                            ->label('Язык')
                            ->formatStateUsing(fn (string $state): string => $state === 'ru' ? 'Русский' : 'Английский'),
                        TextEntry::make('timezone')
                            ->label('Часовой пояс')
                            ->formatStateUsing(fn (?string $state): string => TimezoneOptions::label($state)),
                        TextEntry::make('lead_source')->label('Источник обращения')->placeholder('Не указан'),
                        TextEntry::make('referral_code')->label('Код рекомендации')->placeholder('Не указан'),
                    ])
                    ->columns(4),
                Section::make('Связь и запись')
                    ->schema([
                        TextEntry::make('communication_status')
                            ->label('Каналы связи')
                            ->state(function (Client $record): string {
                                $actor = auth()->user();

                                if (! $actor instanceof User) {
                                    return 'Требуется авторизация';
                                }

                                return implode(', ', app(GetClientCommunicationStatus::class)->handle($actor, $record));
                            })
                            ->placeholder('Каналы не подключены'),
                        TextEntry::make('activeBookingRestriction.reason')
                            ->label('Самостоятельная запись')
                            ->formatStateUsing(fn (?string $state): string => $state === null ? 'Разрешена' : 'Ограничена: '.$state)
                            ->placeholder('Разрешена'),
                        TextEntry::make('balance_summary')
                            ->label('Открытый баланс')
                            ->state(function (Client $record): string {
                                $actor = auth()->user();

                                if (! $actor instanceof User || ! app(FinanceAuthorization::class)->allowsView($actor)) {
                                    return 'Недоступно';
                                }

                                $summary = app(GetClientBalanceSummary::class)->handle($actor, $record);

                                if ($summary === []) {
                                    return 'Открытых начислений нет';
                                }

                                return collect($summary)
                                    ->map(fn (array $item): string => Money::ofMinor($item['outstandingMinor'], $item['currency'])->toDecimalString().' '.$item['currency'])
                                    ->implode(', ');
                            })
                            ->placeholder('Нет данных'),
                    ])
                    ->columns(3),
                Section::make('Медицинский профиль')
                    ->description('Защищённые данные доступны только в контексте открытого клиента.')
                    ->schema([
                        KeyValueEntry::make('medical_profile')
                            ->label('Профиль')
                            ->state(function (Client $record): array {
                                $actor = auth()->user();

                                if (! $actor instanceof User) {
                                    return ['Статус' => 'Требуется авторизация'];
                                }

                                $profile = app(GetMedicalProfile::class)->handle($actor, $record);

                                if ($profile === null) {
                                    return ['Статус' => 'Профиль не заполнен'];
                                }

                                return [
                                    'Анамнез' => $profile->anamnesis ?: 'Не заполнен',
                                    'Жалобы и цели' => $profile->complaintsGoals ?: 'Не указаны',
                                    'Операции и травмы' => $profile->operationsInjuries ?: 'Не указаны',
                                    'Лекарственные препараты' => $profile->medicines ?: 'Не указаны',
                                    'Биологически активные добавки' => $profile->supplements ?: 'Не указаны',
                                ];
                            })
                            ->columnSpanFull(),
                    ])
                    ->collapsible(),
            ]);
    }
}
