<?php

namespace App\Filament\Resources\ReferralPartnerProfiles\Schemas;

use App\Filament\Resources\ReferralPartnerProfiles\Pages\ViewReferralPartnerProfile;
use App\Modules\Referrals\Domain\Enums\ReferralPartnerStatus;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

final class ReferralPartnerProfileInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Партнёр и результаты')
                ->schema([
                    TextEntry::make('client.full_name')->label('Клиент')->wrap(),
                    TextEntry::make('status')
                        ->label('Статус')
                        ->formatStateUsing(fn (ReferralPartnerStatus|string $state): string => $state instanceof ReferralPartnerStatus
                            ? $state->label()
                            : (ReferralPartnerStatus::tryFrom($state)?->label() ?? 'Неизвестно'))
                        ->badge(),
                    TextEntry::make('activated_at')->label('Подключён')->dateTime('d.m.Y H:i'),
                    TextEntry::make('stats_summary')
                        ->label('Результаты и баланс')
                        ->state(fn (ViewReferralPartnerProfile $livewire): string => $livewire->workspaceSummary())
                        ->placeholder('Статистика пока не сформирована')
                        ->wrap()
                        ->columnSpanFull(),
                ])
                ->columns(3)
                ->compact()
                ->columnSpanFull(),
            Section::make('Условия и ссылки')
                ->schema([
                    TextEntry::make('reward_terms')
                        ->label('Правила начисления')
                        ->state(fn (ViewReferralPartnerProfile $livewire): string => $livewire->rewardTermsSummary())
                        ->wrap()
                        ->columnSpanFull(),
                    RepeatableEntry::make('campaign_links')
                        ->label('Личные рекомендации · кампании')
                        ->schema([
                            TextEntry::make('name')
                                ->label('Название')
                                ->weight('semibold')
                                ->columnSpanFull(),
                            TextEntry::make('channel')->label('Канал'),
                            TextEntry::make('shareUrl')
                                ->label('Ссылка')
                                ->url(fn (Get $get): ?string => filled($url = $get('shareUrl')) ? (string) $url : null)
                                ->openUrlInNewTab()
                                ->wrap(),
                            TextEntry::make('visits')->label('Переходы'),
                            TextEntry::make('registrations')->label('Регистрации'),
                            TextEntry::make('paidClients')->label('Оплатили'),
                            TextEntry::make('rewards')->label('Начислено')->wrap(),
                        ])
                        ->columns(2)
                        ->state(fn (ViewReferralPartnerProfile $livewire): array => $livewire->workspaceLinkItems())
                        ->placeholder('Ссылок пока нет')
                        ->columnSpanFull(),
                ])
                ->compact()
                ->columnSpanFull(),
            Section::make('История')
                ->schema([
                    Tabs::make('История партнёрства')
                        ->tabs([
                            Tab::make('Приглашённые клиенты')
                                ->schema([
                                    RepeatableEntry::make('referred_clients')
                                        ->hiddenLabel()
                                        ->schema([
                                            TextEntry::make('name')->label('Клиент')->weight('semibold')->wrap(),
                                            TextEntry::make('registeredAt')->label('Регистрация')->wrap(),
                                            TextEntry::make('origin')->label('Источник')->wrap(),
                                            TextEntry::make('paymentStatus')->label('Оплата')->wrap(),
                                        ])
                                        ->columns(2)
                                        ->state(fn (ViewReferralPartnerProfile $livewire): array => $livewire->workspaceClientItems())
                                        ->placeholder('Регистраций пока нет')
                                        ->columnSpanFull(),
                                ]),
                            Tab::make('Начисления')
                                ->schema([
                                    RepeatableEntry::make('rewards')
                                        ->label('История начислений')
                                        ->schema([
                                            TextEntry::make('type')->label('Операция')->wrap(),
                                            TextEntry::make('amount')->label('Сумма')->wrap(),
                                            TextEntry::make('client')->label('Клиент')->wrap(),
                                            TextEntry::make('reason')->label('Причина')->wrap(),
                                            TextEntry::make('occurredAt')->label('Дата')->wrap(),
                                        ])
                                        ->columns(2)
                                        ->state(fn (ViewReferralPartnerProfile $livewire): array => $livewire->workspaceRewardItems())
                                        ->placeholder('Начислений пока нет')
                                        ->columnSpanFull(),
                                ]),
                            Tab::make('Выплаты')
                                ->schema([
                                    RepeatableEntry::make('payouts')
                                        ->label('История выплат')
                                        ->schema([
                                            TextEntry::make('amount')->label('Сумма')->wrap(),
                                            TextEntry::make('status')->label('Статус')->wrap(),
                                            TextEntry::make('requestedAt')->label('Запрошено')->wrap(),
                                        ])
                                        ->columns(2)
                                        ->state(fn (ViewReferralPartnerProfile $livewire): array => $livewire->workspacePayoutItems())
                                        ->placeholder('Запросов пока нет')
                                        ->columnSpanFull(),
                                ]),
                        ])
                        ->columnSpanFull(),
                ])
                ->compact()
                ->columnSpanFull(),
        ]);
    }
}
