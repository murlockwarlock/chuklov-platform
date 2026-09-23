<?php

namespace App\Filament\Resources\PaymentGatewayReconciliation\Tables;

use App\Filament\Resources\Clients\ClientResource;
use App\Filament\Support\CrmEntityLinks;
use App\Filament\Support\FinancePresentation;
use App\Filament\Support\PaymentGatewayReconciliationPresentation;
use App\Modules\Finance\Domain\Models\PaymentGatewayEvent;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

final class PaymentGatewayReconciliationTable
{
    public static function configure(Table $table): Table
    {
        $canViewClients = ClientResource::canViewAny();

        return $table
            ->stackedOnMobile()
            ->columns([
                TextColumn::make('event_type')
                    ->label(__('Событие'))
                    ->badge()
                    ->state(fn (PaymentGatewayEvent $record): string => PaymentGatewayReconciliationPresentation::eventType($record))
                    ->color('warning'),
                TextColumn::make('client')
                    ->label(__('Клиент'))
                    ->state(fn (PaymentGatewayEvent $record): string => PaymentGatewayReconciliationPresentation::client($record)?->full_name ?? __('Не сопоставлен'))
                    ->wrap()
                    ->url(fn (PaymentGatewayEvent $record): ?string => CrmEntityLinks::clientUrl(
                        PaymentGatewayReconciliationPresentation::client($record),
                        $canViewClients,
                    ))
                    ->color(fn (PaymentGatewayEvent $record): ?string => CrmEntityLinks::clientUrl(
                        PaymentGatewayReconciliationPresentation::client($record),
                        $canViewClients,
                    ) === null ? null : 'primary')
                    ->disabledClick(fn (PaymentGatewayEvent $record): bool => CrmEntityLinks::clientUrl(
                        PaymentGatewayReconciliationPresentation::client($record),
                        $canViewClients,
                    ) === null),
                TextColumn::make('product')
                    ->label(__('Товар / услуга'))
                    ->state(fn (PaymentGatewayEvent $record): string => PaymentGatewayReconciliationPresentation::product($record))
                    ->wrap(),
                TextColumn::make('amount')
                    ->label(__('Сумма'))
                    ->state(fn (PaymentGatewayEvent $record): string => app(FinancePresentation::class)->amount(
                        $record->amount_minor,
                        $record->currency?->value,
                    )),
                TextColumn::make('currency')
                    ->label(__('Валюта'))
                    ->state(fn (PaymentGatewayEvent $record): string => app(FinancePresentation::class)->currencyName(
                        $record->currency?->value,
                    )),
                TextColumn::make('reconciliation_reason')
                    ->label(__('Причина'))
                    ->state(fn (PaymentGatewayEvent $record): string => PaymentGatewayReconciliationPresentation::reason($record))
                    ->wrap(),
                TextColumn::make('created_at')
                    ->label(__('Получено'))
                    ->state(fn (PaymentGatewayEvent $record): string => app(FinancePresentation::class)->timestamp($record->created_at))
                    ->sortable(),
                TextColumn::make('status')
                    ->label(__('Статус'))
                    ->badge()
                    ->state(__('Требует сверки'))
                    ->color('danger'),
            ])
            ->defaultSort('created_at', 'desc')
            ->paginated([10, 25, 50])
            ->emptyStateHeading(__('Событий для сверки нет'))
            ->emptyStateDescription(__('Новые неоднозначные платежные события появятся здесь автоматически.'));
    }
}
