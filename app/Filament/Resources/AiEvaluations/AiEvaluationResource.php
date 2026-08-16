<?php

namespace App\Filament\Resources\AiEvaluations;

use App\Filament\Resources\AiEvaluations\Pages\CreateAiEvaluation;
use App\Filament\Resources\AiEvaluations\Pages\EditAiEvaluation;
use App\Filament\Resources\AiEvaluations\Pages\ListAiEvaluations;
use App\Filament\Resources\AiEvaluations\RelationManagers\CasesRelationManager;
use App\Filament\Resources\AiEvaluations\Schemas\AiEvaluationForm;
use App\Modules\AI\Application\Actions\RunEvaluationSuite;
use App\Modules\AI\Domain\Enums\AiCapability;
use App\Modules\AI\Domain\Models\AiEvalSuite;
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
use Illuminate\Support\Facades\Auth;

final class AiEvaluationResource extends Resource
{
    protected static ?string $model = AiEvalSuite::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBeaker;

    protected static ?string $navigationLabel = 'Тестирование (Evals)';

    protected static string|\UnitEnum|null $navigationGroup = 'Искусственный интеллект';

    protected static ?int $navigationSort = 5;

    protected static ?string $modelLabel = 'набор тестов AI';

    protected static ?string $pluralModelLabel = 'наборы тестов AI';

    public static function form(Schema $schema): Schema
    {
        return AiEvaluationForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label('Название')->searchable()->sortable(),
                TextColumn::make('key')->label('Ключ')->searchable(),
                TextColumn::make('capability')
                    ->label('Возможность')
                    ->formatStateUsing(fn ($state) => $state instanceof AiCapability ? $state->label() : (string) $state),
                TextColumn::make('cases_count')->counts('cases')->label('Тест-кейсов'),
                TextColumn::make('runs_count')->counts('runs')->label('Запусков'),
                TextColumn::make('updated_at')->label('Изменен')->dateTime('d.m.Y H:i')->sortable(),
            ])
            ->recordActions([
                EditAction::make(),
                Action::make('run_evals')
                    ->label('Запустить тесты')
                    ->color('success')
                    ->icon(Heroicon::OutlinedPlay)
                    ->requiresConfirmation()
                    ->modalHeading('Запуск набора тестов')
                    ->modalDescription('Будут последовательно выполнены все активные синтетические тест-кейсы.')
                    ->action(function (AiEvalSuite $record, RunEvaluationSuite $runner) {
                        $user = Auth::user();
                        if (! $user) {
                            return;
                        }

                        $prompt = $record->prompt;
                        $promptVersionId = $prompt !== null
                            ? ($prompt->active_version_id ?? $prompt->versions()->latest('version')->value('id'))
                            : null;

                        if (! $promptVersionId) {
                            Notification::make()->title('У связанного промпта нет активных версий')->danger()->send();

                            return;
                        }

                        $evalRun = $runner->handle(
                            actor: $user,
                            evalSuiteId: $record->id,
                            promptVersionId: $promptVersionId,
                        );

                        Notification::make()
                            ->title("Тестирование завершено: {$evalRun->passed_cases} из {$evalRun->total_cases} пройдено")
                            ->color($evalRun->failed_cases > 0 ? 'warning' : 'success')
                            ->send();
                    }),
            ])
            ->defaultSort('updated_at', 'desc');
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('organization_id', app(OrganizationContext::class)->id())
            ->with(['prompt']);
    }

    public static function getRelations(): array
    {
        return [
            CasesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAiEvaluations::route('/'),
            'create' => CreateAiEvaluation::route('/create'),
            'edit' => EditAiEvaluation::route('/{record}/edit'),
        ];
    }
}
