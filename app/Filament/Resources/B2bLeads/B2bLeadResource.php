<?php

namespace App\Filament\Resources\B2bLeads;

use App\Filament\Resources\B2bLeads\Pages\CreateB2bLead;
use App\Filament\Resources\B2bLeads\Pages\ListB2bLeads;
use App\Filament\Resources\B2bLeads\Pages\ViewB2bLead;
use App\Filament\Resources\B2bLeads\Schemas\B2bLeadForm;
use App\Filament\Resources\B2bLeads\Schemas\B2bLeadInfolist;
use App\Filament\Resources\B2bLeads\Tables\B2bLeadsTable;
use App\Models\User;
use App\Modules\B2B\Application\ListB2bLeadsForCrm;
use App\Modules\B2B\Domain\Models\B2bLead;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/** @extends resource<B2bLead> */
final class B2bLeadResource extends Resource
{
    protected static ?string $model = B2bLead::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRocketLaunch;

    protected static ?string $navigationLabel = 'B2B-лиды';

    protected static string|\UnitEnum|null $navigationGroup = 'Клиенты';

    protected static ?int $navigationSort = 5;

    protected static ?string $modelLabel = 'B2B-лид';

    protected static ?string $pluralModelLabel = 'B2B-лиды';

    public static function canAccess(): bool
    {
        return self::allows(OrganizationPermission::ViewB2bLeads);
    }

    public static function canCreate(): bool
    {
        return self::allows(OrganizationPermission::ManageB2bLeads);
    }

    public static function form(Schema $schema): Schema
    {
        return B2bLeadForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return B2bLeadInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return B2bLeadsTable::configure($table);
    }

    public static function getEloquentQuery(): Builder
    {
        $actor = auth()->user();
        abort_unless($actor instanceof User, 403);

        return app(ListB2bLeadsForCrm::class)->query($actor);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListB2bLeads::route('/'),
            'create' => CreateB2bLead::route('/create'),
            'view' => ViewB2bLead::route('/{record}'),
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
