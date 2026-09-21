<?php

namespace App\Filament\Resources\FinancialObligations;

use App\Filament\Support\FinancePaymentActions;
use App\Filament\Support\FinancePresentation;
use App\Filament\Support\LocalizedRelationManager;
use App\Models\User;
use App\Modules\Finance\Application\FinanceAuthorization;
use App\Modules\Finance\Domain\Models\FinancialLedgerEntry;
use App\Modules\Finance\Domain\Models\FinancialObligation;
use App\Modules\Organizations\Application\OrganizationContext;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

final class FinancialPaymentsRelationManager extends LocalizedRelationManager
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
            ->heading(__('История оплат'))
            ->stackedOnMobile()
            ->modifyQueryUsing(function (Builder $query): Builder {
                return $query
                    ->where('organization_id', app(OrganizationContext::class)->id())
                    ->with(['receipt', 'obligation'])
                    ->withExists(['correction as has_correction']);
            })
            ->columns([
                TextColumn::make('occurred_at')
                    ->label(__('Дата'))
                    ->state(fn (FinancialLedgerEntry $record): string => app(FinancePresentation::class)->timestamp($record->occurred_at))
                    ->sortable(),
                TextColumn::make('amount_summary')
                    ->label(__('Сумма'))
                    ->state(fn (FinancialLedgerEntry $record): string => app(FinancePresentation::class)->ledgerPaymentAmount($record)),
                TextColumn::make('payment_method_summary')
                    ->label(__('Способ оплаты'))
                    ->state(fn (FinancialLedgerEntry $record): string => app(FinancePresentation::class)->paymentMethodLabel($record)),
                TextColumn::make('note')
                    ->label(__('Примечание'))
                    ->placeholder('—')
                    ->limit(80)
                    ->wrap(),
                TextColumn::make('receipt_summary')
                    ->label(__('Квитанция'))
                    ->state(fn (FinancialLedgerEntry $record): string => $record->receipt === null ? '—' : __('Скачать квитанцию'))
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
            ->emptyStateHeading(__('История оплат пуста'));
    }
}
