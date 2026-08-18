<?php

namespace App\Filament\Resources\KnowledgeSources;

use App\Filament\Resources\KnowledgeSources\Pages\CreateKnowledgeSource;
use App\Filament\Resources\KnowledgeSources\Pages\EditKnowledgeSource;
use App\Filament\Resources\KnowledgeSources\Pages\ListKnowledgeSources;
use App\Filament\Resources\KnowledgeSources\RelationManagers\RevisionsRelationManager;
use App\Filament\Resources\KnowledgeSources\Schemas\KnowledgeSourceForm;
use App\Modules\Knowledge\Domain\Models\KnowledgeSource;
use App\Modules\Organizations\Application\OrganizationContext;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

final class KnowledgeSourceResource extends Resource
{
    protected static ?string $model = KnowledgeSource::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBookOpen;

    protected static ?string $navigationLabel = 'База знаний';

    protected static string|\UnitEnum|null $navigationGroup = 'Контент и знания';

    protected static ?int $navigationSort = 3;

    protected static ?string $modelLabel = 'источник знаний';

    protected static ?string $pluralModelLabel = 'источники знаний';

    protected static ?string $breadcrumb = 'База знаний';

    protected static ?string $recordTitleAttribute = 'title';

    public static function form(Schema $schema): Schema
    {
        return KnowledgeSourceForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('title')->label('Название')->searchable()->sortable(),
            TextColumn::make('type')->label('Тип')->formatStateUsing(fn ($state): string => ($state->value ?? $state) === 'authored_text' ? 'Текст' : 'Документ'),
            TextColumn::make('activeRevision.version')->label('В поиске')->placeholder('Нет'),
            TextColumn::make('latestRevision.version')->label('Последняя версия'),
            TextColumn::make('latestRevision.status')->label('Обработка')->formatStateUsing(fn ($state): string => match ($state->value ?? $state) {
                'ready' => 'Готов', 'processing' => 'Обрабатывается', 'failed' => 'Ошибка', 'pending' => 'Ожидает обработки',
                'stale' => 'Предыдущая версия', 'retired' => 'Скрыт', default => 'Нет готовой версии',
            }),
            TextColumn::make('updated_at')->label('Изменён')->dateTime('d.m.Y H:i')->sortable(),
        ])->recordActions([
            EditAction::make(),
        ])->defaultSort('updated_at', 'desc');
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('organization_id', app(OrganizationContext::class)->id())
            ->with(['activeRevision', 'latestRevision']);
    }

    public static function getRelations(): array
    {
        return [RevisionsRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListKnowledgeSources::route('/'),
            'create' => CreateKnowledgeSource::route('/create'),
            'edit' => EditKnowledgeSource::route('/{record}/edit'),
        ];
    }
}
