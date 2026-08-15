<?php

namespace App\Filament\Resources\FinancialObligations;

use App\Filament\Resources\FinancialObligations\Pages\ListFinancialObligations;
use App\Filament\Resources\FinancialObligations\Pages\ViewFinancialObligation;
use App\Filament\Resources\FinancialObligations\Tables\FinancialObligationsTable;
use App\Models\User;
use App\Modules\Finance\Application\FinanceAuthorization;
use App\Modules\Finance\Application\ReconcileFinancialObligation;
use App\Modules\Finance\Domain\Models\FinancialLedgerEntry;
use App\Modules\Finance\Domain\Models\FinancialObligation;
use App\Modules\Finance\Domain\ValueObjects\Money;
use App\Modules\Organizations\Application\OrganizationContext;
use BackedEnum;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

final class FinancialObligationResource extends Resource
{
    protected static ?string $model = FinancialObligation::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static ?string $navigationLabel = 'Задолженности';

    protected static ?string $modelLabel = 'задолженность';

    protected static ?string $pluralModelLabel = 'задолженности';

    protected static string|\UnitEnum|null $navigationGroup = 'Финансы';

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            TextEntry::make('client.full_name')->label('Клиент'),
            TextEntry::make('service.name')->label('Услуга'),
            TextEntry::make('amount_summary')
                ->label('Обязательство')
                ->state(fn (FinancialObligation $record): string => Money::ofMinor($record->amount_minor, $record->currency)->toDecimalString().' '.$record->currency->value),
            TextEntry::make('display_summary')
                ->label('Отображение')
                ->state(fn (FinancialObligation $record): string => Money::ofMinor($record->display_amount_minor, $record->display_currency)->toDecimalString().' '.$record->display_currency->value),
            TextEntry::make('status_summary')
                ->label('Статус оплаты')
                ->state(fn (FinancialObligation $record): string => match (app(ReconcileFinancialObligation::class)->handle((int) $record->organization_id, (int) $record->getKey())->status->value) {
                    'settled' => 'Оплачено',
                    'partially_paid' => 'Оплачено частично',
                    default => 'К оплате',
                }),
            TextEntry::make('outstanding_summary')
                ->label('Осталось')
                ->state(function (FinancialObligation $record): string {
                    $reconciliation = app(ReconcileFinancialObligation::class)->handle((int) $record->organization_id, (int) $record->getKey());

                    return Money::ofMinor(max(0, $reconciliation->outstanding->minorUnits()), $reconciliation->outstanding->currency())->toDecimalString()
                        .' '.$reconciliation->outstanding->currency()->value;
                }),
            TextEntry::make('receipt_summary')
                ->label('Квитанция')
                ->state(fn (FinancialObligation $record): string => $record->ledgerEntries
                    ->first(fn (FinancialLedgerEntry $entry): bool => $entry->receipt !== null)?->receipt === null
                    ? 'Нет'
                    : 'Скачать квитанцию')
                ->url(function (FinancialObligation $record): ?string {
                    $receipt = $record->ledgerEntries
                        ->first(fn (FinancialLedgerEntry $entry): bool => $entry->receipt !== null)
                        ?->receipt;

                    return $receipt === null ? null : route('admin.finance.receipt', $receipt->getKey());
                })
                ->openUrlInNewTab(),
            TextEntry::make('ledger_summary')
                ->label('История проводок')
                ->state(fn (FinancialObligation $record): string => $record->ledgerEntries
                    ->sortBy('id')
                    ->map(fn ($entry): string => ($entry->entry_type->value === 'correction' ? 'Исправление: ' : 'Оплата: ')
                        .Money::ofMinor($entry->amount_minor, $entry->currency)->toDecimalString().' '.$entry->currency->value
                        .' · '.$entry->occurred_at->format('d.m.Y H:i'))
                    ->implode("\n") ?: 'Проводок пока нет')
                ->columnSpanFull(),
            TextEntry::make('created_at')->label('Создано')->dateTime('d.m.Y H:i'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return FinancialObligationsTable::configure($table);
    }

    public static function canAccess(): bool
    {
        $actor = auth()->user();

        return $actor instanceof User && app(FinanceAuthorization::class)->allowsView($actor);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canManage(): bool
    {
        $actor = auth()->user();

        return $actor instanceof User && app(FinanceAuthorization::class)->allowsManage($actor);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('organization_id', app(OrganizationContext::class)->id())
            ->with(['client', 'booking.service', 'service', 'ledgerEntries.receipt']);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListFinancialObligations::route('/'),
            'view' => ViewFinancialObligation::route('/{record}'),
        ];
    }
}
