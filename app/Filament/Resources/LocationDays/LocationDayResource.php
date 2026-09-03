<?php

namespace App\Filament\Resources\LocationDays;

use App\Filament\Resources\LocationDays\Pages\CreateLocationDay;
use App\Filament\Resources\LocationDays\Pages\EditLocationDay;
use App\Filament\Resources\LocationDays\Pages\ListLocationDays;
use App\Filament\Resources\LocationDays\Schemas\LocationDayForm;
use App\Filament\Resources\LocationDays\Tables\LocationDaysTable;
use App\Models\User;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use App\Modules\Scheduling\Domain\Models\LocationDay;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class LocationDayResource extends Resource
{
    protected static ?string $model = LocationDay::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    protected static ?string $navigationLabel = 'Дни выезда';

    protected static string|\UnitEnum|null $navigationGroup = 'Настройки';

    protected static ?int $navigationSort = 2;

    protected static ?string $modelLabel = 'день выезда';

    protected static ?string $pluralModelLabel = 'дни выезда';

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
        return $record instanceof LocationDay
            && (int) $record->organization_id === app(OrganizationContext::class)->id()
            && self::allows(OrganizationPermission::ManageScheduling);
    }

    public static function form(Schema $schema): Schema
    {
        return LocationDayForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return LocationDaysTable::configure($table);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('organization_id', app(OrganizationContext::class)->id());
    }

    public static function getPages(): array
    {
        return [
            'index' => ListLocationDays::route('/'),
            'create' => CreateLocationDay::route('/create'),
            'edit' => EditLocationDay::route('/{record}/edit'),
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
