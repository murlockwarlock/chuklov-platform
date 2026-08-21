<?php

namespace App\Filament\Resources\FinancialObligations;

use App\Filament\Resources\FinancialObligations\Pages\ListFinancialObligations;
use App\Filament\Resources\FinancialObligations\Pages\ViewFinancialObligation;
use App\Filament\Resources\FinancialObligations\Tables\FinancialObligationsTable;
use App\Models\User;
use App\Modules\Finance\Application\FinanceAuthorization;
use App\Modules\Finance\Application\ListFinancialObligationsForCrm;
use App\Modules\Finance\Domain\Models\FinancialObligation;
use App\Modules\Organizations\Application\OrganizationContext;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** @extends resource<FinancialObligation> */
final class FinancialObligationResource extends Resource
{
    protected static ?string $model = FinancialObligation::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static ?string $navigationLabel = 'Оплаты';

    protected static ?string $modelLabel = 'расчёт';

    protected static ?string $pluralModelLabel = 'оплаты';

    protected static ?string $breadcrumb = 'Оплаты';

    protected static string|\UnitEnum|null $navigationGroup = 'Финансы';

    protected static ?int $navigationSort = 2;

    public static function infolist(Schema $schema): Schema
    {
        return FinancialObligationInfolist::configure($schema);
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
        return app(ListFinancialObligationsForCrm::class)->query(
            app(OrganizationContext::class)->id(),
        );
    }

    public static function getRelations(): array
    {
        return [FinancialPaymentsRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListFinancialObligations::route('/'),
            'view' => ViewFinancialObligation::route('/{record}'),
        ];
    }
}
