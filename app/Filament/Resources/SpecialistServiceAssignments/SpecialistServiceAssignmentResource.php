<?php

namespace App\Filament\Resources\SpecialistServiceAssignments;

use App\Filament\Resources\SpecialistServiceAssignments\Pages\CreateSpecialistServiceAssignment;
use App\Filament\Resources\SpecialistServiceAssignments\Pages\ListSpecialistServiceAssignments;
use App\Filament\Resources\SpecialistServiceAssignments\Schemas\SpecialistServiceAssignmentForm;
use App\Filament\Resources\SpecialistServiceAssignments\Tables\SpecialistServiceAssignmentsTable;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Scheduling\Domain\Models\SpecialistServiceAssignment;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class SpecialistServiceAssignmentResource extends Resource
{
    protected static ?string $model = SpecialistServiceAssignment::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static ?string $navigationLabel = 'Специалисты и услуги';

    protected static string|\UnitEnum|null $navigationGroup = 'Команда и услуги';

    protected static ?int $navigationSort = 3;

    protected static ?string $modelLabel = 'услуга специалиста';

    protected static ?string $pluralModelLabel = 'услуги специалистов';

    protected static ?string $breadcrumb = 'Специалисты и услуги';

    public static function form(Schema $schema): Schema
    {
        return SpecialistServiceAssignmentForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return SpecialistServiceAssignmentsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('organization_id', app(OrganizationContext::class)->id())
            ->with(['specialist', 'service']);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSpecialistServiceAssignments::route('/'),
            'create' => CreateSpecialistServiceAssignment::route('/create'),
        ];
    }
}
