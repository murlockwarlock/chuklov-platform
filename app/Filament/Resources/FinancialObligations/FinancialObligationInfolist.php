<?php

namespace App\Filament\Resources\FinancialObligations;

use App\Filament\Resources\Bookings\BookingResource;
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
            Section::make('Расчёт с клиентом')
                ->schema([
                    TextEntry::make('client.full_name')
                        ->label('Клиент'),
                    TextEntry::make('booking_summary')
                        ->label('Запись')
                        ->state(fn (FinancialObligation $record): string => $record->booking === null ? '—' : 'Запись на приём')
                        ->url(fn (FinancialObligation $record): ?string => $record->booking === null
                            ? null
                            : BookingResource::getUrl('view', ['record' => $record->booking->getKey()])),
                    TextEntry::make('service.name')
                        ->label('Услуга'),
                    TextEntry::make('visit_date')
                        ->label('Дата визита')
                        ->state(fn (FinancialObligation $record): string => app(FinancePresentation::class)->visitDate($record->booking)),
                    TextEntry::make('display_amount')
                        ->label('Сумма к оплате')
                        ->state(fn (FinancialObligation $record): string => app(FinancePresentation::class)->displayAmount($record)),
                    TextEntry::make('display_paid')
                        ->label('Оплачено')
                        ->state(fn (FinancialObligation $record): string => app(FinancePresentation::class)->money(
                            app(FinancePresentation::class)->reconciliation($record)?->displayApplied,
                        )),
                    TextEntry::make('display_outstanding')
                        ->label('Осталось')
                        ->state(fn (FinancialObligation $record): string => app(FinancePresentation::class)->money(
                            app(FinancePresentation::class)->reconciliation($record)?->displayOutstanding,
                        )),
                    TextEntry::make('finance_status')
                        ->label('Статус')
                        ->badge()
                        ->state(fn (FinancialObligation $record): string => app(FinancePresentation::class)->status(
                            app(FinancePresentation::class)->reconciliation($record),
                        ))
                        ->color(fn (FinancialObligation $record): string => app(FinancePresentation::class)->statusColor(
                            app(FinancePresentation::class)->reconciliation($record),
                        )),
                    TextEntry::make('finance_error')
                        ->label('Состояние расчёта')
                        ->state('Расчёт недоступен. Проверьте историю оплат.')
                        ->color('danger')
                        ->visible(fn (FinancialObligation $record): bool => app(FinancePresentation::class)->reconciliation($record) === null)
                        ->columnSpanFull(),
                    TextEntry::make('created_at_summary')
                        ->label('Расчёт создан')
                        ->state(fn (FinancialObligation $record): string => app(FinancePresentation::class)->timestamp($record->created_at)),
                ])
                ->columns(2),

            Section::make('Подробнее о расчёте')
                ->collapsed()
                ->schema([
                    TextEntry::make('original_amount')
                        ->label('Первоначальная сумма')
                        ->state(fn (FinancialObligation $record): string => app(FinancePresentation::class)->amount($record->amount_minor, $record->currency)),
                    TextEntry::make('practice_currency')
                        ->label('Валюта практики')
                        ->state(fn (FinancialObligation $record): string => app(FinancePresentation::class)->currencyName($record->base_currency)),
                    TextEntry::make('settlement_currency')
                        ->label('Валюта расчёта')
                        ->state(fn (FinancialObligation $record): string => app(FinancePresentation::class)->currencyName($record->settlement_currency)),
                    TextEntry::make('display_currency')
                        ->label('Валюта отображения')
                        ->state(fn (FinancialObligation $record): string => app(FinancePresentation::class)->currencyName($record->display_currency)),
                    TextEntry::make('historical_rate')
                        ->label('Курс при создании расчёта')
                        ->state(fn (FinancialObligation $record): ?string => app(FinancePresentation::class)->historicalRate($record))
                        ->placeholder('Не применялся'),
                    TextEntry::make('rounding_mode')
                        ->label('Правило округления')
                        ->state(fn (FinancialObligation $record): ?string => app(FinancePresentation::class)->roundingMode($record))
                        ->placeholder('Не указано'),
                ])
                ->columns(2),
        ]);
    }
}
