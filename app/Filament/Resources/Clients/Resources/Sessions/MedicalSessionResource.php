<?php

namespace App\Filament\Resources\Clients\Resources\Sessions;

use App\Filament\Resources\Clients\ClientResource;
use App\Filament\Resources\Clients\Resources\Sessions\Pages\CreateMedicalSession;
use App\Filament\Resources\Clients\Resources\Sessions\Pages\EditMedicalSession;
use App\Filament\Resources\Clients\Resources\Sessions\Pages\ViewMedicalSession;
use App\Filament\Resources\Clients\Resources\Sessions\Schemas\SessionForm;
use App\Filament\Resources\Clients\Resources\Sessions\Schemas\SessionInfolist;
use App\Filament\Resources\Clients\Resources\Sessions\Tables\SessionsTable;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Sessions\Domain\Models\MedicalSession;
use BackedEnum;
use Filament\Resources\ParentResourceRegistration;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class MedicalSessionResource extends Resource
{
    protected static ?string $model = MedicalSession::class;

    protected static ?string $parentResource = ClientResource::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static ?string $modelLabel = 'сеанс';

    protected static ?string $pluralModelLabel = 'сеансы';

    protected static ?string $recordTitleAttribute = 'occurred_at';

    public static function form(Schema $schema): Schema
    {
        return SessionForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return SessionInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return SessionsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'create' => CreateMedicalSession::route('/create'),
            'view' => ViewMedicalSession::route('/{record}'),
            'edit' => EditMedicalSession::route('/{record}/edit'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('organization_id', app(OrganizationContext::class)->id());
    }

    public static function getParentResourceRegistration(): ParentResourceRegistration
    {
        return ClientResource::asParent(MedicalSessionResource::class)
            ->relationship('sessions')
            ->inverseRelationship('client');
    }
}
