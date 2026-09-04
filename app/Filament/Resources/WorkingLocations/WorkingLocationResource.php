<?php

namespace App\Filament\Resources\WorkingLocations;

use App\Filament\Resources\WorkingLocations\Pages\CreateWorkingLocation;
use App\Filament\Resources\WorkingLocations\Pages\EditWorkingLocation;
use App\Filament\Resources\WorkingLocations\Pages\ListWorkingLocations;
use App\Filament\Resources\WorkingLocations\Schemas\WorkingLocationForm;
use App\Filament\Resources\WorkingLocations\Tables\WorkingLocationsTable;
use App\Models\User;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use App\Modules\Scheduling\Domain\Models\WorkingLocation;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class WorkingLocationResource extends Resource
{
    protected static ?string $model = WorkingLocation::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMapPin;

    protected static ?string $navigationLabel = 'Локации';

    protected static string|\UnitEnum|null $navigationGroup = 'Настройки';

    protected static ?int $navigationSort = 1;

    protected static ?string $modelLabel = 'локация';

    protected static ?string $pluralModelLabel = 'локации';

    public static function canViewAny(): bool
    {
        return self::allows(OrganizationPermission::ViewScheduling);
    }

    public static function canCreate(): bool
    {
        return self::allows(OrganizationPermission::ManageScheduling);
    }

    public static function canEdit(Model $record): bool
    {
        return $record instanceof WorkingLocation
            && (int) $record->organization_id === app(OrganizationContext::class)->id()
            && self::allows(OrganizationPermission::ManageScheduling);
    }

    public static function form(Schema $schema): Schema
    {
        return WorkingLocationForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return WorkingLocationsTable::configure($table);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('organization_id', app(OrganizationContext::class)->id());
    }

    public static function getPages(): array
    {
        return [
            'index' => ListWorkingLocations::route('/'),
            'create' => CreateWorkingLocation::route('/create'),
            'edit' => EditWorkingLocation::route('/{record}/edit'),
        ];
    }

    private static function allows(OrganizationPermission $permission): bool
    {
        $actor = auth()->user();

        return $actor instanceof User && app(OrganizationAuthorizer::class)->allows(
            $actor,
            app(OrganizationContext::class)->organization(),
            $permission,
        );
    }
}
