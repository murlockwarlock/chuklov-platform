<?php

namespace App\Filament\Resources\ReferralPartnerProfiles\Schemas;

use App\Filament\Resources\ReferralPartnerProfiles\Pages\ViewReferralPartnerProfile;
use App\Filament\Support\CrmEntityLinks;
use App\Filament\Support\CrmLabel;
use App\Modules\Referrals\Domain\Enums\ReferralPartnerStatus;
use App\Modules\Referrals\Domain\Models\ReferralPartnerProfile;
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
            Section::make(__('Партнёр и результаты'))
                ->schema([
                    TextEntry::make('client.full_name')
                        ->label(__('Клиент'))
                        ->wrap()
                        ->url(fn (ReferralPartnerProfile $record): ?string => CrmEntityLinks::clientUrl($record->client))
                        ->color(fn (ReferralPartnerProfile $record): ?string => CrmEntityLinks::clientUrl($record->client) === null ? null : 'primary'),
                    TextEntry::make('status')
                        ->label(__('Статус'))
                        ->formatStateUsing(fn (ReferralPartnerStatus|string $state): string => $state instanceof ReferralPartnerStatus
                            ? CrmLabel::enum($state)
                            : (CrmLabel::enum(ReferralPartnerStatus::tryFrom($state)) ?? __('Неизвестно')))
                        ->badge(),
                    TextEntry::make('activated_at')->label(__('Подключён'))->dateTime('d.m.Y H:i'),
                    TextEntry::make('stats_summary')
                        ->label(__('Результаты и баланс'))
                        ->state(fn (ViewReferralPartnerProfile $livewire): string => $livewire->workspaceSummary())
                        ->placeholder(__('Статистика пока не сформирована'))
                        ->wrap()
                        ->columnSpanFull(),
                ])
                ->columns(3)
                ->compact()
                ->columnSpanFull(),
            Section::make(__('Условия и ссылки'))
                ->schema([
                    TextEntry::make('reward_terms')
                        ->label(__('Правила начисления'))
                        ->state(fn (ViewReferralPartnerProfile $livewire): string => $livewire->rewardTermsSummary())
                        ->wrap()
                        ->columnSpanFull(),
                    RepeatableEntry::make('campaign_links')
                        ->label(__('Личные рекомендации · кампании'))
                        ->schema([
                            TextEntry::make('name')
                                ->label(__('Название'))
                                ->weight('semibold')
                                ->columnSpanFull(),
                            TextEntry::make('channel')->label(__('Канал')),
                            TextEntry::make('shareUrl')
                                ->label(__('Ссылка'))
                                ->url(fn (Get $get): ?string => filled($url = $get('shareUrl')) ? (string) $url : null)
                                ->openUrlInNewTab()
                                ->wrap(),
                            TextEntry::make('visits')->label(__('Переходы')),
                            TextEntry::make('registrations')->label(__('Регистрации')),
                            TextEntry::make('paidClients')->label(__('Оплатили')),
                            TextEntry::make('rewards')->label(__('Начислено'))->wrap(),
                        ])
                        ->columns(2)
                        ->state(fn (ViewReferralPartnerProfile $livewire): array => $livewire->workspaceLinkItems())
                        ->placeholder(__('Ссылок пока нет'))
                        ->columnSpanFull(),
                ])
                ->compact()
                ->columnSpanFull(),
            Section::make(__('История'))
                ->schema([
                    Tabs::make(__('История партнёрства'))
                        ->tabs([
                            Tab::make(__('Приглашённые клиенты'))
                                ->schema([
                                    RepeatableEntry::make('referred_clients')
                                        ->hiddenLabel()
                                        ->schema([
                                            TextEntry::make('name')->label(__('Клиент'))->weight('semibold')->wrap(),
                                            TextEntry::make('registeredAt')->label(__('Регистрация'))->wrap(),
                                            TextEntry::make('origin')->label(__('Источник'))->wrap(),
                                            TextEntry::make('paymentStatus')->label(__('Оплата'))->wrap(),
                                        ])
                                        ->columns(2)
                                        ->state(fn (ViewReferralPartnerProfile $livewire): array => $livewire->workspaceClientItems())
                                        ->placeholder(__('Регистраций пока нет'))
                                        ->columnSpanFull(),
                                ]),
                            Tab::make(__('Начисления'))
                                ->schema([
                                    RepeatableEntry::make('rewards')
                                        ->label(__('История начислений'))
                                        ->schema([
                                            TextEntry::make('type')->label(__('Операция'))->wrap(),
                                            TextEntry::make('amount')->label(__('Сумма'))->wrap(),
                                            TextEntry::make('client')->label(__('Клиент'))->wrap(),
                                            TextEntry::make('reason')->label(__('Причина'))->wrap(),
                                            TextEntry::make('occurredAt')->label(__('Дата'))->wrap(),
                                        ])
                                        ->columns(2)
                                        ->state(fn (ViewReferralPartnerProfile $livewire): array => $livewire->workspaceRewardItems())
                                        ->placeholder(__('Начислений пока нет'))
                                        ->columnSpanFull(),
                                ]),
                            Tab::make(__('Выплаты'))
                                ->schema([
                                    RepeatableEntry::make('payouts')
                                        ->label(__('История выплат'))
                                        ->schema([
                                            TextEntry::make('amount')->label(__('Сумма'))->wrap(),
                                            TextEntry::make('status')->label(__('Статус'))->wrap(),
                                            TextEntry::make('requestedAt')->label(__('Запрошено'))->wrap(),
                                        ])
                                        ->columns(2)
                                        ->state(fn (ViewReferralPartnerProfile $livewire): array => $livewire->workspacePayoutItems())
                                        ->placeholder(__('Запросов пока нет'))
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
