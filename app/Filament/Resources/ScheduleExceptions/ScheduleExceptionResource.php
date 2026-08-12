<?php

namespace App\Filament\Resources\ScheduleExceptions;

use App\Filament\Resources\ScheduleExceptions\Pages\CreateScheduleException;
use App\Filament\Resources\ScheduleExceptions\Pages\ListScheduleExceptions;
use App\Filament\Resources\ScheduleExceptions\Schemas\ScheduleExceptionForm;
use App\Filament\Resources\ScheduleExceptions\Tables\ScheduleExceptionsTable;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Scheduling\Domain\Models\ScheduleException;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ScheduleExceptionResource extends Resource
{
    protected static ?string $model = ScheduleException::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static ?string $navigationLabel = 'Schedule exceptions';

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('organization_id', app(OrganizationContext::class)->id())
            ->with('specialist');
    }

    public static function form(Schema $schema): Schema
    {
        return ScheduleExceptionForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ScheduleExceptionsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListScheduleExceptions::route('/'),
            'create' => CreateScheduleException::route('/create'),
        ];
    }
}
