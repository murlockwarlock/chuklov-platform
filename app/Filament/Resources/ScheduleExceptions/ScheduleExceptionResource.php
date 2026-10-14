<?php

namespace App\Filament\Resources\ScheduleExceptions;

use App\Filament\Resources\ScheduleExceptions\Pages\CreateScheduleException;
use App\Filament\Resources\ScheduleExceptions\Pages\ListScheduleExceptions;
use App\Filament\Resources\ScheduleExceptions\Schemas\ScheduleExceptionForm;
use App\Filament\Resources\ScheduleExceptions\Tables\ScheduleExceptionsTable;
use App\Filament\Support\LocalizedResource;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Scheduling\Domain\Enums\ScheduleExceptionType;
use App\Modules\Scheduling\Domain\Models\ScheduleException;
use BackedEnum;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class ScheduleExceptionResource extends LocalizedResource
{
    protected static ?string $model = ScheduleException::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static ?string $navigationLabel = 'Изменения расписания';

    protected static string|\UnitEnum|null $navigationGroup = 'Записи';

    protected static ?int $navigationSort = 4;

    protected static ?string $modelLabel = 'изменение расписания';

    protected static ?string $pluralModelLabel = 'изменения расписания';

    protected static ?string $breadcrumb = 'Изменения расписания';

    public static function getEloquentQuery(): Builder
    {
        $organizationId = app(OrganizationContext::class)->id();
        $exceptions = DB::table('schedule_exceptions')
            ->where('organization_id', $organizationId)
            ->select([
                DB::raw('CAST(id AS TEXT) as id'),
                'id as source_id',
                'organization_id',
                'specialist_id',
                'exception_date',
                'exception_type',
                'start_time',
                'end_time',
                DB::raw('NULL as starts_at'),
                DB::raw('NULL as ends_at'),
                'reason',
                DB::raw("'exception' as change_kind"),
                'is_active',
            ]);
        $unavailable = DB::table('unavailable_periods')
            ->where('organization_id', $organizationId)
            ->whereNull('b2b_sales_call_id')
            ->select([
                DB::raw('CAST(-id AS TEXT) as id'),
                'id as source_id',
                'organization_id',
                'specialist_id',
                DB::raw('NULL as exception_date'),
                DB::raw("'".ScheduleExceptionType::DayOff->value."' as exception_type"),
                DB::raw('NULL as start_time'),
                DB::raw('NULL as end_time'),
                'starts_at',
                'ends_at',
                'reason',
                DB::raw("'unavailable' as change_kind"),
                DB::raw('TRUE as is_active'),
            ]);

        return ScheduleException::query()
            ->fromSub($exceptions->unionAll($unavailable), 'schedule_exceptions')
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
