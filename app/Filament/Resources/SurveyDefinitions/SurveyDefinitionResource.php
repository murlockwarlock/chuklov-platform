<?php

namespace App\Filament\Resources\SurveyDefinitions;

use App\Filament\Resources\SurveyDefinitions\Pages\CreateSurveyDefinition;
use App\Filament\Resources\SurveyDefinitions\Pages\EditSurveyDefinition;
use App\Filament\Resources\SurveyDefinitions\Pages\ListSurveyDefinitions;
use App\Filament\Resources\SurveyDefinitions\Schemas\SurveyDefinitionForm;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Surveys\Domain\Models\SurveyDefinition;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

final class SurveyDefinitionResource extends Resource
{
    protected static ?string $model = SurveyDefinition::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentCheck;

    protected static ?string $navigationLabel = 'Тесты';

    protected static ?string $modelLabel = 'тест';

    protected static ?string $pluralModelLabel = 'тесты';

    protected static ?string $recordTitleAttribute = 'title';

    public static function form(Schema $schema): Schema
    {
        return SurveyDefinitionForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('title')->label('Название')->searchable()->sortable(),
            TextColumn::make('activeVersion.version')->label('Опубликованная версия')->placeholder('Нет'),
            TextColumn::make('versions_count')->label('Всего версий'),
            IconColumn::make('is_available')->label('Доступен')->boolean(),
        ])->recordActions([
            EditAction::make(),
        ])->defaultSort('title');
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('organization_id', app(OrganizationContext::class)->id())->with('activeVersion')->withCount('versions');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSurveyDefinitions::route('/'),
            'create' => CreateSurveyDefinition::route('/create'),
            'edit' => EditSurveyDefinition::route('/{record}/edit'),
        ];
    }
}
