<?php

namespace App\Filament\Resources\ReferralPartnerProfiles\Schemas;

use App\Filament\Resources\ReferralPartnerProfiles\Pages\ViewReferralPartnerProfile;
use App\Modules\Referrals\Domain\Enums\ReferralPartnerStatus;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

final class ReferralPartnerProfileInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Партнёр')
                ->schema([
                    TextEntry::make('client.full_name')->label('Клиент')->wrap(),
                    TextEntry::make('status')
                        ->label('Статус')
                        ->formatStateUsing(fn (ReferralPartnerStatus|string $state): string => $state instanceof ReferralPartnerStatus
                            ? $state->label()
                            : (ReferralPartnerStatus::tryFrom($state)?->label() ?? 'Неизвестно'))
                        ->badge(),
                    TextEntry::make('activated_at')->label('Подключён')->dateTime('d.m.Y H:i'),
                ])
                ->columns(3),
            Section::make('Сводка')
                ->schema([
                    TextEntry::make('stats_summary')
                        ->label('Результаты и баланс')
                        ->state(fn (ViewReferralPartnerProfile $livewire): string => $livewire->workspaceSummary())
                        ->placeholder('Статистика пока не сформирована')
                        ->wrap()
                        ->columnSpanFull(),
                ]),
            Section::make('Ссылки')
                ->schema([
                    TextEntry::make('links_summary')
                        ->label('Кампании и статистика')
                        ->state(fn (ViewReferralPartnerProfile $livewire): string => $livewire->workspaceLinks())
                        ->placeholder('Ссылок пока нет')
                        ->wrap()
                        ->columnSpanFull(),
                ]),
            Section::make('Приглашённые клиенты')
                ->schema([
                    TextEntry::make('referred_clients_summary')
                        ->label('Регистрации и оплаты')
                        ->state(fn (ViewReferralPartnerProfile $livewire): string => $livewire->workspaceClients())
                        ->placeholder('Регистраций пока нет')
                        ->wrap()
                        ->columnSpanFull(),
                ]),
            Section::make('Вознаграждения и выплаты')
                ->schema([
                    TextEntry::make('reward_summary')
                        ->label('История начислений')
                        ->state(fn (ViewReferralPartnerProfile $livewire): string => $livewire->workspaceRewards())
                        ->placeholder('Начислений пока нет')
                        ->wrap()
                        ->columnSpanFull(),
                    TextEntry::make('payout_summary')
                        ->label('История выплат')
                        ->state(fn (ViewReferralPartnerProfile $livewire): string => $livewire->workspacePayouts())
                        ->placeholder('Запросов пока нет')
                        ->wrap()
                        ->columnSpanFull(),
                ])
                ->columns(2),
        ]);
    }
}
