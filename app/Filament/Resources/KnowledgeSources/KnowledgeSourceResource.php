<?php

namespace App\Filament\Resources\KnowledgeSources;

use App\Filament\Resources\KnowledgeSources\Pages\CreateKnowledgeSource;
use App\Filament\Resources\KnowledgeSources\Pages\EditKnowledgeSource;
use App\Filament\Resources\KnowledgeSources\Pages\ListKnowledgeSources;
use App\Filament\Resources\KnowledgeSources\RelationManagers\RevisionsRelationManager;
use App\Filament\Resources\KnowledgeSources\Schemas\KnowledgeSourceForm;
use App\Filament\Support\KnowledgeSourcePresentation;
use App\Models\User;
use App\Modules\Knowledge\Application\DeleteKnowledgeSource;
use App\Modules\Knowledge\Domain\Models\KnowledgeIngestionRun;
use App\Modules\Knowledge\Domain\Models\KnowledgeSource;
use App\Modules\Knowledge\Domain\ValueObjects\EmbeddingConfiguration;
use App\Modules\Organizations\Application\OrganizationContext;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;

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
        $presentation = app(KnowledgeSourcePresentation::class);

        return $table->columns([
            TextColumn::make('title')->label('Название')->searchable()->sortable(),
            TextColumn::make('type')->label('Тип')->formatStateUsing(fn ($state): string => $presentation->sourceType($state)),
            TextColumn::make('search_availability')->label('Доступность')->state(fn (KnowledgeSource $record): string => $presentation->searchAvailability($record)),
            TextColumn::make('latest_processing')->label('Обработка')->state(fn (KnowledgeSource $record): string => $presentation->latestProcessing($record)),
            TextColumn::make('created_at')->label('Добавлен')->dateTime('d.m.Y H:i')->sortable(),
            TextColumn::make('updated_at')->label('Изменён')->dateTime('d.m.Y H:i')->sortable(),
        ])
            ->emptyStateHeading('В базе знаний пока нет материалов')
            ->emptyStateDescription('Добавьте текст или загрузите файл Markdown/TXT, чтобы использовать его в ответах клиентам.')
            ->recordActions([
                EditAction::make(),
                Action::make('delete')
                    ->label('Удалить')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading('Удалить материал из базы знаний?')
                    ->modalDescription('Материал, его поисковые фрагменты и загруженный файл будут удалены. Это действие нельзя отменить.')
                    ->action(function (KnowledgeSource $record): void {
                        $actor = auth()->user();
                        abort_unless($actor instanceof User, 403);
                        app(DeleteKnowledgeSource::class)->handle($actor, $record);
                        Notification::make()->title('Материал удалён из базы знаний')->success()->send();
                    }),
            ])->defaultSort('updated_at', 'desc');
    }

    public static function getEloquentQuery(): Builder
    {
        $configuration = EmbeddingConfiguration::active();
        $processingStaleCutoff = now()->subSeconds((int) config('rag.processing_stale_after_seconds'));
        $ingestionRunTable = (new KnowledgeIngestionRun)->getTable();

        return parent::getEloquentQuery()
            ->where('organization_id', app(OrganizationContext::class)->id())
            ->with([
                'activeRevision' => function (Relation $query) use ($configuration, $processingStaleCutoff, $ingestionRunTable): void {
                    $query
                        ->select(['id', 'organization_id', 'knowledge_source_id', 'status', 'ready_at'])
                        ->withExists([
                            'ingestionRuns as has_compatible_ready_run' => function (Builder $query) use ($configuration, $ingestionRunTable): void {
                                $query
                                    ->where("{$ingestionRunTable}.status", 'ready')
                                    ->where("{$ingestionRunTable}.embedding_provider", $configuration->provider)
                                    ->where("{$ingestionRunTable}.embedding_model", $configuration->model)
                                    ->where("{$ingestionRunTable}.embedding_dimensions", $configuration->dimensions)
                                    ->where("{$ingestionRunTable}.embedding_configuration_version", $configuration->version);
                            },
                            'ingestionRuns as has_compatible_processing_run' => function (Builder $query) use ($configuration, $processingStaleCutoff, $ingestionRunTable): void {
                                $query
                                    ->where("{$ingestionRunTable}.status", 'processing')
                                    ->whereNotNull("{$ingestionRunTable}.processing_started_at")
                                    ->where("{$ingestionRunTable}.processing_started_at", '>=', $processingStaleCutoff)
                                    ->where("{$ingestionRunTable}.embedding_provider", $configuration->provider)
                                    ->where("{$ingestionRunTable}.embedding_model", $configuration->model)
                                    ->where("{$ingestionRunTable}.embedding_dimensions", $configuration->dimensions)
                                    ->where("{$ingestionRunTable}.embedding_configuration_version", $configuration->version);
                            },
                        ]);
                },
                'latestRevision' => function (Relation $query): void {
                    $query
                        ->select([
                            'knowledge_revisions.id',
                            'knowledge_revisions.organization_id',
                            'knowledge_revisions.knowledge_source_id',
                            'knowledge_revisions.version',
                            'knowledge_revisions.status',
                            'knowledge_revisions.original_filename',
                            'knowledge_revisions.created_at',
                        ])
                        ->with([
                            'latestIngestionRun' => function (Relation $query): void {
                                $query->select([
                                    'knowledge_ingestion_runs.id',
                                    'knowledge_ingestion_runs.organization_id',
                                    'knowledge_ingestion_runs.knowledge_source_id',
                                    'knowledge_ingestion_runs.knowledge_revision_id',
                                    'knowledge_ingestion_runs.status',
                                    'knowledge_ingestion_runs.error_code',
                                    'knowledge_ingestion_runs.completed_at',
                                ]);
                            },
                        ]);
                },
            ]);
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
