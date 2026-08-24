<?php

namespace App\Filament\Resources\KnowledgeSources\RelationManagers;

use App\Filament\Support\KnowledgeSourcePresentation;
use App\Models\User;
use App\Modules\Knowledge\Application\GetTemporaryKnowledgeRevisionUrl;
use App\Modules\Knowledge\Application\ReprocessKnowledgeForSearch;
use App\Modules\Knowledge\Application\RetryKnowledgeIngestion;
use App\Modules\Knowledge\Application\StartPendingKnowledgeIngestion;
use App\Modules\Knowledge\Domain\Enums\KnowledgeRevisionStatus;
use App\Modules\Knowledge\Domain\Models\KnowledgeIngestionRun;
use App\Modules\Knowledge\Domain\Models\KnowledgeRevision;
use App\Modules\Knowledge\Domain\Models\KnowledgeSource;
use App\Modules\Knowledge\Domain\ValueObjects\EmbeddingConfiguration;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;

final class RevisionsRelationManager extends RelationManager
{
    protected static bool $isLazy = false;

    protected static string $relationship = 'revisions';

    protected static ?string $title = 'История материалов';

    public function table(Table $table): Table
    {
        $actor = auth()->user();
        $source = $this->getOwnerRecord();
        abort_unless($actor instanceof User, 403);
        abort_unless($source instanceof KnowledgeSource, 404);
        $source->loadMissing('latestRevision');
        $presentation = app(KnowledgeSourcePresentation::class);
        $revisionTable = (new KnowledgeRevision)->getTable();
        $ingestionRunTable = (new KnowledgeIngestionRun)->getTable();

        return $table
            ->columns([
                TextColumn::make('version')->label('Версия')->sortable(["{$revisionTable}.version"]),
                TextColumn::make('original_filename')
                    ->label('Материал')
                    ->state(fn (KnowledgeRevision $record): string => $presentation->materialName($record))
                    ->limit(42)
                    ->wrap(),
                TextColumn::make('status')
                    ->label('Состояние')
                    ->formatStateUsing(fn (KnowledgeRevisionStatus|string $state): string => $presentation->revisionStatus($state)),
                TextColumn::make('processing_result')
                    ->label('Результат обработки')
                    ->state(fn (KnowledgeRevision $record): string => $presentation->errorMessage($record->latestIngestionRun?->error_code)),
                TextColumn::make('latestIngestionRun.completed_at')
                    ->label('Обработана')
                    ->dateTime('d.m.Y H:i')
                    ->placeholder('—'),
                TextColumn::make('created_at')->label('Создана')->dateTime('d.m.Y H:i'),
            ])
            ->modifyQueryUsing(function (Builder $query) use ($revisionTable, $ingestionRunTable): Builder {
                $configuration = EmbeddingConfiguration::active();
                $processingStaleCutoff = now()->subSeconds((int) config('rag.processing_stale_after_seconds'));

                return $query
                    ->select([
                        "{$revisionTable}.id",
                        "{$revisionTable}.organization_id",
                        "{$revisionTable}.knowledge_source_id",
                        "{$revisionTable}.version",
                        "{$revisionTable}.status",
                        "{$revisionTable}.original_filename",
                        "{$revisionTable}.storage_disk",
                        "{$revisionTable}.storage_path",
                        "{$revisionTable}.mime_type",
                        "{$revisionTable}.size_bytes",
                        "{$revisionTable}.created_at",
                    ])
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
                    ])
                    ->with([
                        'latestIngestionRun' => function (Relation $query) use ($ingestionRunTable): void {
                            $query->select([
                                "{$ingestionRunTable}.id",
                                "{$ingestionRunTable}.organization_id",
                                "{$ingestionRunTable}.knowledge_source_id",
                                "{$ingestionRunTable}.knowledge_revision_id",
                                "{$ingestionRunTable}.status",
                                "{$ingestionRunTable}.error_code",
                                "{$ingestionRunTable}.completed_at",
                            ]);
                        },
                    ]);
            })
            ->recordActions([
                Action::make('download')
                    ->label('Скачать файл')
                    ->visible(fn (KnowledgeRevision $record): bool => $presentation->canDownload($source, $record))
                    ->action(function (KnowledgeRevision $record) use ($actor, $source): mixed {
                        return redirect()->to(app(GetTemporaryKnowledgeRevisionUrl::class)->handle($actor, $source, $record));
                    }),
                Action::make('retry')
                    ->label('Повторить обработку')
                    ->color('warning')
                    ->visible(fn (KnowledgeRevision $record): bool => app(KnowledgeSourcePresentation::class)->canRetry($source, $record))
                    ->action(function (KnowledgeRevision $record) use ($actor, $source): void {
                        app(RetryKnowledgeIngestion::class)->handle($actor, $source, $record->getKey());
                        Notification::make()->title('Повторная обработка запущена')->success()->send();
                    }),
                Action::make('startPending')
                    ->label('Запустить обработку')
                    ->color('warning')
                    ->visible(fn (KnowledgeRevision $record): bool => $presentation->canStartPending($source, $record))
                    ->action(function (KnowledgeRevision $record) use ($actor, $source): void {
                        app(StartPendingKnowledgeIngestion::class)->handle($actor, $source, $record->getKey());
                        Notification::make()->title('Обработка запущена')->success()->send();
                    }),
                Action::make('reprocessForSearch')
                    ->label('Переобработать для поиска')
                    ->color('warning')
                    ->visible(fn (KnowledgeRevision $record): bool => $presentation->canReprocessForSearch($source, $record))
                    ->action(function (KnowledgeRevision $record) use ($actor, $source): void {
                        app(ReprocessKnowledgeForSearch::class)->handle($actor, $source, $record->getKey());
                        Notification::make()->title('Подготовка для поиска запущена')->success()->send();
                    }),
            ])
            ->defaultSort(
                fn (Builder $query): Builder => $query->orderBy("{$revisionTable}.version", 'desc'),
                'desc',
            )
            ->paginated([10, 25]);
    }
}
