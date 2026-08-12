<?php

namespace App\Filament\Resources\ContentSections;

use App\Filament\Resources\ContentSections\Pages\CreateContentSection;
use App\Filament\Resources\ContentSections\Pages\EditContentSection;
use App\Filament\Resources\ContentSections\Pages\ListContentSections;
use App\Filament\Resources\ContentSections\Pages\ViewContentSection;
use App\Filament\Resources\ContentSections\Schemas\ContentSectionForm;
use App\Filament\Resources\ContentSections\Tables\ContentSectionsTable;
use App\Modules\Content\Domain\Models\ContentSection;
use App\Modules\Organizations\Application\OrganizationContext;
use BackedEnum;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ContentSectionResource extends Resource
{
    protected static ?string $model = ContentSection::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static ?string $recordTitleAttribute = 'title';

    public static function form(Schema $schema): Schema
    {
        return ContentSectionForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('section_key')->label('Section'),
                TextEntry::make('locale'),
                TextEntry::make('title'),
                TextEntry::make('body')->columnSpanFull(),
                TextEntry::make('media')->columnSpanFull(),
                TextEntry::make('sort_order')->label('Order'),
                TextEntry::make('is_visible')->label('Visible'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return ContentSectionsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('organization_id', app(OrganizationContext::class)->id());
    }

    public static function getPages(): array
    {
        return [
            'index' => ListContentSections::route('/'),
            'create' => CreateContentSection::route('/create'),
            'view' => ViewContentSection::route('/{record}'),
            'edit' => EditContentSection::route('/{record}/edit'),
        ];
    }
}
