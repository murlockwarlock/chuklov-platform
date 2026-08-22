<?php

namespace App\Filament\Resources\AiEvaluations;

use App\Filament\Resources\AiEvaluations\Pages\CreateAiEvaluation;
use App\Filament\Resources\AiEvaluations\Pages\EditAiEvaluation;
use App\Filament\Resources\AiEvaluations\Pages\ListAiEvaluations;
use App\Filament\Resources\AiEvaluations\RelationManagers\CasesRelationManager;
use App\Filament\Resources\AiEvaluations\Schemas\AiEvaluationForm;
use App\Modules\AI\Application\Actions\RunEvaluationSuite;
use App\Modules\AI\Domain\Enums\AiCapability;
use App\Modules\AI\Domain\Enums\PromptVersionStatus;
use App\Modules\AI\Domain\Models\AiEvalSuite;
use App\Modules\AI\Domain\Models\AiModelRelease;
use App\Modules\AI\Domain\Models\AiPromptVersion;
use App\Modules\AI\Domain\Registry\AiProviderCatalog;
use App\Modules\Organizations\Application\OrganizationContext;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
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

    protected static ?string $navigationLabel = 'Проверки AI';

    protected static string|\UnitEnum|null $navigationGroup = 'Искусственный интеллект';

    protected static ?int $navigationSort = 5;

    protected static ?string $modelLabel = 'проверка AI';

    protected static ?string $pluralModelLabel = 'проверки AI';

    protected static ?string $breadcrumb = 'Проверки AI';

    public static function form(Schema $schema): Schema
    {
        return AiEvaluationForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label('Название')->searchable()->sortable(),
                TextColumn::make('capability')
                    ->label('Что проверяем')
                    ->formatStateUsing(fn ($state) => $state instanceof AiCapability ? $state->label() : (string) $state),
                TextColumn::make('cases_count')->counts('cases')->label('Примеров'),
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
                    ->modalHeading('Проверить качество AI')
                    ->modalDescription('Будут последовательно выполнены все активные примеры проверки. Данные должны оставаться искусственными или обезличенными.')
                    ->form([
                        Select::make('prompt_version_id')
                            ->label('Версия промпта')
                            ->options(fn (AiEvalSuite $record): array => self::promptVersionOptions($record))
                            ->getSearchResultsUsing(fn (string $search, AiEvalSuite $record): array => self::promptVersionOptions($record, $search))
                            ->getOptionLabelUsing(fn (mixed $value, AiEvalSuite $record): ?string => self::promptVersionLabel($record, $value))
                            ->optionsLimit(50)
                            ->searchable()
                            ->native(false)
                            ->required(),
                        Select::make('model_release_id')
                            ->label('Модель для проверки')
                            ->options(fn (AiEvalSuite $record): array => self::modelReleaseOptions($record))
                            ->getSearchResultsUsing(fn (string $search, AiEvalSuite $record): array => self::modelReleaseOptions($record, $search))
                            ->getOptionLabelUsing(fn (mixed $value, AiEvalSuite $record): ?string => self::modelReleaseLabel($record, $value))
                            ->optionsLimit(50)
                            ->searchable()
                            ->native(false)
                            ->required(),
                    ])
                    ->action(function (AiEvalSuite $record, array $data, RunEvaluationSuite $runner) {
                        $user = Auth::user();
                        if (! $user) {
                            return;
                        }

                        $evalRun = $runner->handle(
                            actor: $user,
                            evalSuiteId: $record->id,
                            promptVersionId: (int) $data['prompt_version_id'],
                            modelReleaseId: (int) $data['model_release_id'],
                        );

                        Notification::make()
                            ->title("Тестирование завершено: {$evalRun->passed_cases} из {$evalRun->total_cases} пройдено")
                            ->color($evalRun->failed_cases > 0 ? 'warning' : 'success')
                            ->send();
                    }),
            ])
            ->emptyStateHeading('Проверок AI пока нет')
            ->emptyStateDescription('Создайте набор примеров, чтобы проверить качество ответов AI перед использованием нового промпта или модели.')
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

    /** @return array<int|string, string> */
    private static function promptVersionOptions(AiEvalSuite $suite, string $search = ''): array
    {
        $query = AiPromptVersion::query()
            ->where('organization_id', app(OrganizationContext::class)->id())
            ->where('prompt_id', $suite->prompt_id)
            ->whereIn('status', [PromptVersionStatus::Active->value, PromptVersionStatus::Retired->value]);

        $search = trim($search);
        if ($search !== '') {
            $version = preg_replace('/^v/i', '', $search);
            $query->where('version', ctype_digit((string) $version) ? (int) $version : 0);
        }

        return $query
            ->orderByDesc('version')
            ->limit(50)
            ->get(['id', 'version', 'status'])
            ->mapWithKeys(static fn (AiPromptVersion $version): array => [
                $version->getKey() => self::promptVersionDisplayLabel($version),
            ])
            ->all();
    }

    private static function promptVersionLabel(AiEvalSuite $suite, mixed $value): ?string
    {
        if (! is_scalar($value) || ! is_numeric($value)) {
            return null;
        }

        $version = AiPromptVersion::query()
            ->where('organization_id', app(OrganizationContext::class)->id())
            ->where('prompt_id', $suite->prompt_id)
            ->whereKey((int) $value)
            ->first(['id', 'version', 'status']);

        return $version instanceof AiPromptVersion
            ? self::promptVersionDisplayLabel($version)
            : 'Сохранённая версия промпта недоступна';
    }

    /** @return array<int|string, string> */
    private static function modelReleaseOptions(AiEvalSuite $suite, string $search = ''): array
    {
        $query = AiModelRelease::query()
            ->where('organization_id', app(OrganizationContext::class)->id())
            ->whereIn('status', ['active', 'retired'])
            ->whereJsonContains('capabilities', $suite->capability->value);

        $search = trim($search);
        if ($search !== '') {
            $query->where(function (Builder $nested) use ($search): void {
                $nested
                    ->where('provider_name', 'like', '%'.$search.'%')
                    ->orWhere('model_name', 'like', '%'.$search.'%');
            });
        }

        return $query
            ->orderByDesc('id')
            ->limit(50)
            ->with(['modelConfiguration:id,display_name'])
            ->get(['id', 'model_config_id', 'provider_name', 'model_name', 'release_number', 'status'])
            ->mapWithKeys(static fn (AiModelRelease $release): array => [
                $release->getKey() => self::modelReleaseDisplayLabel($release),
            ])
            ->all();
    }

    private static function modelReleaseLabel(AiEvalSuite $suite, mixed $value): ?string
    {
        if (! is_scalar($value) || ! is_numeric($value)) {
            return null;
        }

        $release = AiModelRelease::query()
            ->where('organization_id', app(OrganizationContext::class)->id())
            ->whereKey((int) $value)
            ->with(['modelConfiguration:id,display_name'])
            ->first(['id', 'model_config_id', 'provider_name', 'model_name', 'release_number', 'status']);

        return $release instanceof AiModelRelease
            ? self::modelReleaseDisplayLabel($release)
            : 'Сохранённая модель недоступна';
    }

    private static function promptVersionDisplayLabel(AiPromptVersion $version): string
    {
        return "v{$version->version} · ".$version->status->label();
    }

    private static function modelReleaseDisplayLabel(AiModelRelease $release): string
    {
        try {
            $provider = AiProviderCatalog::label($release->provider_name);
        } catch (\InvalidArgumentException) {
            $provider = 'Провайдер требует проверки';
        }

        return $provider.' · '.$release->modelConfiguration->display_name.' · версия '.$release->release_number;
    }
}
