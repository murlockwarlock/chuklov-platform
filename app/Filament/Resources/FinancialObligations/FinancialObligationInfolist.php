<?php

namespace App\Filament\Resources\FinancialObligations;

use App\Filament\Resources\Bookings\BookingResource;
use App\Filament\Support\CommerceFulfillmentPresentation;
use App\Filament\Support\CrmEntityLinks;
use App\Filament\Support\FinancePresentation;
use App\Modules\Finance\Domain\Models\FinancialObligation;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

final class FinancialObligationInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('Расчёт с клиентом'))
                ->schema([
                    TextEntry::make('client_name')
                        ->label(__('Клиент'))
                        ->state(function (FinancialObligation $record): string {
                            $client = $record->client;

                            return $client === null ? '—' : ($client->full_name ?? '—');
                        })
                        ->url(fn (FinancialObligation $record): ?string => CrmEntityLinks::clientUrl($record->client))
                        ->color(fn (FinancialObligation $record): ?string => CrmEntityLinks::clientUrl($record->client) === null ? null : 'primary'),
                    TextEntry::make('booking_summary')
                        ->label(__('Запись'))
                        ->state(fn (FinancialObligation $record): string => $record->booking !== null
                            ? __('Запись на приём')
                            : ($record->purchase !== null ? __('Покупка') : '—'))
                        ->url(fn (FinancialObligation $record): ?string => $record->booking === null
                            ? null
                            : BookingResource::getUrl('view', ['record' => $record->booking->getKey()])),
                    TextEntry::make('service_name')
                        ->label(__('Товар / услуга'))
                        ->state(fn (FinancialObligation $record): string => CommerceFulfillmentPresentation::productName($record)),
                    TextEntry::make('visit_date')
                        ->label(__('Дата визита'))
                        ->state(fn (FinancialObligation $record): string => app(FinancePresentation::class)->visitDate($record->booking)),
                    TextEntry::make('display_amount')
                        ->label(__('Сумма к оплате'))
                        ->state(fn (FinancialObligation $record): string => app(FinancePresentation::class)->displayAmount($record)),
                    TextEntry::make('display_paid')
                        ->label(__('Оплачено'))
                        ->state(fn (FinancialObligation $record): string => app(FinancePresentation::class)->money(
                            app(FinancePresentation::class)->reconciliation($record)?->displayApplied,
                        )),
                    TextEntry::make('display_outstanding')
                        ->label(__('Осталось'))
                        ->state(fn (FinancialObligation $record): string => app(FinancePresentation::class)->money(
                            app(FinancePresentation::class)->reconciliation($record)?->displayOutstanding,
                        )),
                    TextEntry::make('finance_status')
                        ->label(__('Статус'))
                        ->badge()
                        ->state(fn (FinancialObligation $record): string => app(FinancePresentation::class)->status(
                            app(FinancePresentation::class)->reconciliation($record),
                        ))
                        ->color(fn (FinancialObligation $record): string => app(FinancePresentation::class)->statusColor(
                            app(FinancePresentation::class)->reconciliation($record),
                        )),
                    TextEntry::make('fulfillment_status')
                        ->label(__('Выдача доступа'))
                        ->state(fn (FinancialObligation $record): string => CommerceFulfillmentPresentation::status($record))
                        ->visible(fn (FinancialObligation $record): bool => $record->purchase !== null)
                        ->badge(),
                    TextEntry::make('finance_error')
                        ->label(__('Состояние расчёта'))
                        ->state(__('Расчёт недоступен. Проверьте историю оплат.'))
                        ->color('danger')
                        ->visible(fn (FinancialObligation $record): bool => app(FinancePresentation::class)->reconciliation($record) === null)
                        ->columnSpanFull(),
                    TextEntry::make('created_at_summary')
                        ->label(__('Расчёт создан'))
                        ->state(fn (FinancialObligation $record): string => app(FinancePresentation::class)->timestamp($record->created_at)),
                ])
                ->columns(2),

            Section::make(__('Подробнее о расчёте'))
                ->collapsed()
                ->schema([
                    TextEntry::make('original_amount')
                        ->label(__('Первоначальная сумма'))
                        ->state(fn (FinancialObligation $record): string => app(FinancePresentation::class)->obligationAmount(
                            $record,
                            'amount_minor',
                            'currency',
                        )),
                    TextEntry::make('practice_currency_summary')
                        ->label(__('Валюта практики'))
                        ->state(fn (FinancialObligation $record): string => app(FinancePresentation::class)->currencyName(
                            $record->getRawOriginal('base_currency'),
                        )),
                    TextEntry::make('settlement_currency_summary')
                        ->label(__('Валюта расчёта'))
                        ->state(fn (FinancialObligation $record): string => app(FinancePresentation::class)->currencyName(
                            $record->getRawOriginal('settlement_currency'),
                        )),
                    TextEntry::make('display_currency_summary')
                        ->label(__('Валюта отображения'))
                        ->state(fn (FinancialObligation $record): string => app(FinancePresentation::class)->currencyName(
                            $record->getRawOriginal('display_currency'),
                        )),
                    TextEntry::make('historical_rate')
                        ->label(__('Курс при создании расчёта'))
                        ->state(fn (FinancialObligation $record): ?string => app(FinancePresentation::class)->historicalRate($record))
                        ->placeholder(__('Не применялся')),
                    TextEntry::make('rounding_mode')
                        ->label(__('Правило округления'))
                        ->state(fn (FinancialObligation $record): ?string => app(FinancePresentation::class)->roundingMode($record))
                        ->placeholder(__('Не указано')),
                ])
                ->columns(2),
        ]);
    }
}
