<?php

namespace App\Filament\Resources\FinancialObligations\Tables;

use App\Filament\Resources\Bookings\BookingResource;
use App\Filament\Resources\Bookings\Support\BookingLocalDateRange;
use App\Filament\Resources\Clients\ClientResource;
use App\Filament\Support\CommerceFulfillmentActions;
use App\Filament\Support\CommerceFulfillmentPresentation;
use App\Filament\Support\CrmEntityLinks;
use App\Filament\Support\FinancePaymentActions;
use App\Filament\Support\FinancePresentation;
use App\Modules\Finance\Application\ListFinancialObligationsForCrm;
use App\Modules\Finance\Domain\Enums\FinancialStatus;
use App\Modules\Finance\Domain\Models\FinancialObligation;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Scheduling\Domain\Models\Booking;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

final class FinancialObligationsTable
{
    public static function configure(Table $table): Table
    {
        $canViewClients = ClientResource::canViewAny();

        return $table
            ->stackedOnMobile()
            ->columns([
                TextColumn::make('client.full_name')
                    ->label('Клиент')
                    ->searchable()
                    ->sortable()
                    ->wrap()
                    ->url(fn (FinancialObligation $record): ?string => CrmEntityLinks::clientUrl($record->client, $canViewClients))
                    ->color(fn (FinancialObligation $record): ?string => CrmEntityLinks::clientUrl($record->client, $canViewClients) === null ? null : 'primary')
                    ->disabledClick(fn (FinancialObligation $record): bool => CrmEntityLinks::clientUrl($record->client, $canViewClients) === null),
                TextColumn::make('service.name')
                    ->label('Товар / услуга')
                    ->state(fn (FinancialObligation $record): string => CommerceFulfillmentPresentation::productName($record))
                    ->searchable()
                    ->sortable()
                    ->wrap(),
                TextColumn::make('visit_date')
                    ->label('Дата визита')
                    ->state(fn (FinancialObligation $record): string => app(FinancePresentation::class)->visitDate($record->booking))
                    ->wrap(),
                TextColumn::make('amount_summary')
                    ->label('Сумма')
                    ->state(fn (FinancialObligation $record): string => app(FinancePresentation::class)->displayAmount($record)),
                TextColumn::make('paid_summary')
                    ->label('Оплачено')
                    ->state(fn (FinancialObligation $record): string => app(FinancePresentation::class)->money(
                        app(FinancePresentation::class)->reconciliation($record)?->displayApplied,
                    )),
                TextColumn::make('outstanding_summary')
                    ->label('Осталось')
                    ->state(fn (FinancialObligation $record): string => app(FinancePresentation::class)->money(
                        app(FinancePresentation::class)->reconciliation($record)?->displayOutstanding,
                    )),
                TextColumn::make('financial_status')
                    ->label('Статус')
                    ->badge()
                    ->state(fn (FinancialObligation $record): string => app(FinancePresentation::class)->status(
                        app(FinancePresentation::class)->reconciliation($record),
                    ))
                    ->color(fn (FinancialObligation $record): string => app(FinancePresentation::class)->statusColor(
                        app(FinancePresentation::class)->reconciliation($record),
                    )),
                TextColumn::make('fulfillment_status')
                    ->label('Выдача')
                    ->state(fn (FinancialObligation $record): string => CommerceFulfillmentPresentation::status($record))
                    ->badge()
                    ->color(fn (FinancialObligation $record): string => match (CommerceFulfillmentPresentation::status($record)) {
                        'Доступ выдан' => 'success',
                        'Требуется выдача' => 'warning',
                        'Ошибка выдачи' => 'danger',
                        'Выдаётся', 'Выдаётся автоматически' => 'info',
                        default => 'gray',
                    }),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Статус')
                    ->options([
                        FinancialStatus::Outstanding->value => 'К оплате',
                        FinancialStatus::PartiallyPaid->value => 'Оплачено частично',
                        FinancialStatus::Settled->value => 'Оплачено',
                    ])
                    ->query(fn (Builder $query, array $data): Builder => app(ListFinancialObligationsForCrm::class)->applyStatusFilter(
                        $query,
                        isset($data['value']) ? (string) $data['value'] : null,
                    )),
                SelectFilter::make('client')
                    ->label('Клиент')
                    ->relationship(
                        'client',
                        'full_name',
                        fn (Builder $query): Builder => $query
                            ->where('organization_id', app(OrganizationContext::class)->id())
                            ->orderBy('full_name')
                            ->orderBy('id'),
                    )
                    ->getOptionLabelFromRecordUsing(
                        static fn (Client $record): string => is_string($record->full_name) && filled($record->full_name)
                            ? $record->full_name
                            : 'Имя не указано',
                    )
                    ->searchable()
                    ->preload()
                    ->optionsLimit(50),
                SelectFilter::make('service')
                    ->label('Услуга')
                    ->relationship(
                        'service',
                        'name',
                        fn (Builder $query): Builder => $query
                            ->where('organization_id', app(OrganizationContext::class)->id())
                            ->orderBy('name')
                            ->orderBy('id'),
                    )
                    ->searchable()
                    ->preload()
                    ->optionsLimit(50),
                Filter::make('visit_date')
                    ->label('Дата визита')
                    ->schema([
                        DatePicker::make('from')->label('С'),
                        DatePicker::make('until')->label('По'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        if (blank($data['from'] ?? null) && blank($data['until'] ?? null)) {
                            return $query;
                        }

                        return $query->whereHas('booking', function (Builder $bookingQuery) use ($data): void {
                            $bookingQuery->where('organization_id', app(OrganizationContext::class)->id());
                            BookingLocalDateRange::apply(
                                $bookingQuery,
                                $data['from'] ?? null,
                                $data['until'] ?? null,
                                app(OrganizationContext::class)->defaultTimezone(),
                            );
                        });
                    }),
            ])
            ->recordActions([
                ViewAction::make()
                    ->label('Открыть')
                    ->icon(Heroicon::OutlinedEye)
                    ->iconButton()
                    ->tooltip('Открыть расчёт'),
                ActionGroup::make([
                    FinancePaymentActions::forObligation(),
                    CommerceFulfillmentActions::forObligation(),
                    Action::make('openBooking')
                        ->label('Открыть запись')
                        ->color('gray')
                        ->visible(fn (FinancialObligation $record): bool => $record->booking instanceof Booking)
                        ->url(fn (FinancialObligation $record): ?string => $record->booking instanceof Booking
                            ? BookingResource::getUrl('view', ['record' => $record->booking->getKey()])
                            : null),
                ])
                    ->label('Действия')
                    ->icon('heroicon-o-ellipsis-vertical')
                    ->button()
                    ->color('gray'),
            ])
            ->emptyStateHeading('Оплат пока нет')
            ->emptyStateDescription('Расчёты появятся после покупки или завершения визита.');
    }
}
