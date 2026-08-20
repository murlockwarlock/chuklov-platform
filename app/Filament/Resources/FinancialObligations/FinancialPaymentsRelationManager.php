<?php

namespace App\Filament\Resources\FinancialObligations;

use App\Filament\Support\FinancePaymentActions;
use App\Filament\Support\FinancePresentation;
use App\Models\User;
use App\Modules\Finance\Application\FinanceAuthorization;
use App\Modules\Finance\Domain\Models\FinancialLedgerEntry;
use App\Modules\Finance\Domain\Models\FinancialObligation;
use App\Modules\Organizations\Application\OrganizationContext;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

final class FinancialPaymentsRelationManager extends RelationManager
{
    protected static string $relationship = 'ledgerEntries';

    protected static ?string $title = 'История оплат';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        $actor = auth()->user();

        return $actor instanceof User
            && $ownerRecord instanceof FinancialObligation
            && app(FinanceAuthorization::class)->allowsView($actor)
            && (int) $ownerRecord->organization_id === app(OrganizationContext::class)->id();
    }

    public function table(Table $table): Table
    {
        $actor = auth()->user();
        $obligation = $this->getOwnerRecord();
        abort_unless($actor instanceof User, 403);
        abort_unless($obligation instanceof FinancialObligation, 404);
        app(FinanceAuthorization::class)->assertOwned($obligation);

        return $table
            ->heading('История оплат')
            ->stackedOnMobile()
            ->modifyQueryUsing(function (Builder $query): Builder {
                return $query
                    ->where('organization_id', app(OrganizationContext::class)->id())
                    ->with('receipt')
                    ->withExists(['correction as has_correction']);
            })
            ->columns([
                TextColumn::make('occurred_at')
                    ->label('Дата')
                    ->state(fn (FinancialLedgerEntry $record): string => app(FinancePresentation::class)->timestamp($record->occurred_at))
                    ->sortable(),
                TextColumn::make('amount_summary')
                    ->label('Сумма')
                    ->state(fn (FinancialLedgerEntry $record): string => app(FinancePresentation::class)->amount(
                        $record->payment_amount_minor,
                        $record->payment_currency,
                    )),
                TextColumn::make('payment_method_summary')
                    ->label('Способ оплаты')
                    ->state(fn (FinancialLedgerEntry $record): string => app(FinancePresentation::class)->paymentMethodLabel($record)),
                TextColumn::make('note')
                    ->label('Примечание')
                    ->placeholder('—')
                    ->limit(80)
                    ->wrap(),
                TextColumn::make('receipt_summary')
                    ->label('Квитанция')
                    ->state(fn (FinancialLedgerEntry $record): string => $record->receipt === null ? '—' : 'Скачать квитанцию')
                    ->url(fn (FinancialLedgerEntry $record): ?string => $record->receipt === null
                        ? null
                        : route('admin.finance.receipt', $record->receipt->getKey()))
                    ->openUrlInNewTab(),
            ])
            ->recordActions([
                FinancePaymentActions::correction(),
            ])
            ->defaultSort('occurred_at', 'desc')
            ->paginated([10, 25, 50])
            ->emptyStateHeading('История оплат пуста');
    }
}
