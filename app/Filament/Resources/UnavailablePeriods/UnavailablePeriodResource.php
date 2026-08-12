<?php

namespace App\Filament\Resources\UnavailablePeriods;

use App\Filament\Resources\UnavailablePeriods\Pages\CreateUnavailablePeriod;
use App\Filament\Resources\UnavailablePeriods\Pages\ListUnavailablePeriods;
use App\Filament\Resources\UnavailablePeriods\Schemas\UnavailablePeriodForm;
use App\Filament\Resources\UnavailablePeriods\Tables\UnavailablePeriodsTable;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Scheduling\Domain\Models\UnavailablePeriod;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class UnavailablePeriodResource extends Resource
{
    protected static ?string $model = UnavailablePeriod::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static ?string $navigationLabel = 'Unavailable periods';

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('organization_id', app(OrganizationContext::class)->id())
            ->with('specialist');
    }

    public static function form(Schema $schema): Schema
    {
        return UnavailablePeriodForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return UnavailablePeriodsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListUnavailablePeriods::route('/'),
            'create' => CreateUnavailablePeriod::route('/create'),
        ];
    }
}
