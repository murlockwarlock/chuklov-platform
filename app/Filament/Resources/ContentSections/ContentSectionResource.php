<?php

namespace App\Filament\Resources\ContentSections;

use App\Filament\Resources\ContentSections\Pages\CreateContentSection;
use App\Filament\Resources\ContentSections\Pages\EditContentSection;
use App\Filament\Resources\ContentSections\Pages\ListContentSections;
use App\Filament\Resources\ContentSections\Pages\ViewContentSection;
use App\Filament\Resources\ContentSections\Schemas\ContentSectionForm;
use App\Filament\Resources\ContentSections\Tables\ContentSectionsTable;
use App\Filament\Support\RichTextPresentation;
use App\Modules\Content\Domain\Enums\ContentDeliveryMode;
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

    protected static ?string $navigationLabel = 'Разделы контента';

    protected static string|\UnitEnum|null $navigationGroup = 'Контент и знания';

    protected static ?int $navigationSort = 1;

    protected static ?string $modelLabel = 'раздел контента';

    protected static ?string $pluralModelLabel = 'разделы контента';

    protected static ?string $breadcrumb = 'Разделы контента';

    protected static ?string $recordTitleAttribute = 'title';

    public static function form(Schema $schema): Schema
    {
        return ContentSectionForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('section_key')
                    ->label('Раздел')
                    ->formatStateUsing(fn (string $state): string => self::sectionLabel($state)),
                TextEntry::make('locale')
                    ->label('Язык')
                    ->formatStateUsing(fn (string $state): string => $state === 'ru' ? 'Русский' : 'Английский'),
                TextEntry::make('title')->label('Название'),
                TextEntry::make('delivery_mode')
                    ->label('Где показывать')
                    ->formatStateUsing(fn ($state): string => match ($state instanceof ContentDeliveryMode ? $state->value : (string) $state) {
                        'telegram' => 'Telegram',
                        'mini_app' => 'Mini App',
                        default => 'Telegram и Mini App',
                    }),
                TextEntry::make('body')
                    ->label('Текст')
                    ->formatStateUsing(fn (?string $state): string => RichTextPresentation::html($state))
                    ->html()
                    ->prose()
                    ->wrap()
                    ->columnSpanFull(),
                TextEntry::make('media')
                    ->label('Изображение')
                    ->formatStateUsing(fn (?array $state): string => $state === null ? 'Не добавлено' : 'Добавлено')
                    ->columnSpanFull(),
                TextEntry::make('sort_order')->label('Порядок показа'),
                TextEntry::make('is_visible')->label('Показывать'),
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

    private static function sectionLabel(string $section): string
    {
        return match ($section) {
            'author' => 'Об академии',
            'method' => 'Методика',
            'b2b' => 'Для бизнеса',
            'partner' => 'Партнёрам',
            'hidden' => 'Скрытый раздел',
            default => 'Раздел',
        };
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
