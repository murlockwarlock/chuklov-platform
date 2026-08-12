<?php

namespace App\Filament\Resources\Specialists;

use App\Filament\Resources\Specialists\Pages\CreateSpecialist;
use App\Filament\Resources\Specialists\Pages\EditSpecialist;
use App\Filament\Resources\Specialists\Pages\ListSpecialists;
use App\Filament\Resources\Specialists\Pages\ViewSpecialist;
use App\Filament\Resources\Specialists\Schemas\SpecialistForm;
use App\Filament\Resources\Specialists\Tables\SpecialistsTable;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Specialists\Domain\Models\Specialist;
use BackedEnum;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class SpecialistResource extends Resource
{
    protected static ?string $model = Specialist::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedIdentification;

    protected static ?string $recordTitleAttribute = 'display_name';

    public static function form(Schema $schema): Schema
    {
        return SpecialistForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('display_name')->label('Full name'),
                TextEntry::make('is_active')->label('Active'),
                TextEntry::make('timezone')->placeholder('Organization timezone fallback'),
                TextEntry::make('staffUser.name')->label('Linked staff User')->placeholder('Not linked'),
                TextEntry::make('created_at')->dateTime(),
                TextEntry::make('updated_at')->dateTime(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return SpecialistsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('organization_id', app(OrganizationContext::class)->id())
            ->with('staffUser');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSpecialists::route('/'),
            'create' => CreateSpecialist::route('/create'),
            'view' => ViewSpecialist::route('/{record}'),
            'edit' => EditSpecialist::route('/{record}/edit'),
        ];
    }
}
