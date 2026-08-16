<?php

namespace App\Filament\Resources\Specialists;

use App\Filament\Resources\Specialists\Pages\CreateSpecialist;
use App\Filament\Resources\Specialists\Pages\EditSpecialist;
use App\Filament\Resources\Specialists\Pages\ListSpecialists;
use App\Filament\Resources\Specialists\Pages\ViewSpecialist;
use App\Filament\Resources\Specialists\Schemas\SpecialistForm;
use App\Filament\Resources\Specialists\Tables\SpecialistsTable;
use App\Filament\Support\TimezoneOptions;
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

    protected static ?string $navigationLabel = 'Специалисты';

    protected static string|\UnitEnum|null $navigationGroup = 'Команда и услуги';

    protected static ?int $navigationSort = 1;

    protected static ?string $modelLabel = 'специалист';

    protected static ?string $pluralModelLabel = 'специалисты';

    protected static ?string $recordTitleAttribute = 'display_name';

    public static function form(Schema $schema): Schema
    {
        return SpecialistForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('display_name')->label('Имя специалиста'),
                TextEntry::make('is_active')->label('Доступен'),
                TextEntry::make('timezone')
                    ->label('Часовой пояс')
                    ->formatStateUsing(fn (?string $state): string => $state === null
                        ? 'Часовой пояс организации'
                        : TimezoneOptions::label($state)),
                TextEntry::make('staffUser.name')->label('Сотрудник CRM')->placeholder('Не привязан'),
                TextEntry::make('created_at')->label('Создано')->dateTime('d.m.Y H:i'),
                TextEntry::make('updated_at')->label('Изменено')->dateTime('d.m.Y H:i'),
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
