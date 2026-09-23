<?php

namespace App\Filament\Resources\AiPrompts;

use App\Filament\Resources\AiPrompts\Pages\CreateAiPrompt;
use App\Filament\Resources\AiPrompts\Pages\EditAiPrompt;
use App\Filament\Resources\AiPrompts\Pages\ListAiPrompts;
use App\Filament\Resources\AiPrompts\RelationManagers\PromptVersionsRelationManager;
use App\Filament\Resources\AiPrompts\Schemas\AiPromptForm;
use App\Filament\Resources\AiRuns\AiRunResource;
use App\Filament\Support\AiPlaygroundResultPresentation;
use App\Filament\Support\CrmLabel;
use App\Filament\Support\LocalizedResource;
use App\Modules\AI\Application\Actions\ExecutePlaygroundRun;
use App\Modules\AI\Domain\Enums\AiCapability;
use App\Modules\AI\Domain\Models\AiModelRelease;
use App\Modules\AI\Domain\Models\AiPrompt;
use App\Modules\AI\Domain\Registry\AiProviderCatalog;
use App\Modules\Organizations\Application\OrganizationContext;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Throwable;

final class AiPromptResource extends LocalizedResource
{
    protected static ?string $model = AiPrompt::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSparkles;

    protected static ?string $navigationLabel = 'Промпты и версии';

    protected static string|\UnitEnum|null $navigationGroup = 'Искусственный интеллект';

    protected static ?int $navigationSort = 3;

    protected static ?string $modelLabel = 'промпт';

    protected static ?string $pluralModelLabel = 'промпты';

    protected static ?string $breadcrumb = 'Промпты и версии';

    public static function form(Schema $schema): Schema
    {
        return AiPromptForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->stackedOnMobile()
            ->columns([
                TextColumn::make('name')->label(__('Название'))->searchable()->sortable(),
                TextColumn::make('capability')
                    ->label(__('Используется для'))
                    ->formatStateUsing(fn ($state) => $state instanceof AiCapability ? CrmLabel::enum($state) : (string) $state),
                TextColumn::make('activeVersion.version')->label(__('Активный текст'))->placeholder(__('Нет активного'))->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('versions_count')->counts('versions')->label(__('Всего текстов'))->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')->label(__('Изменён'))->dateTime('d.m.Y H:i')->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->emptyStateHeading(__('Промптов пока нет'))
            ->emptyStateDescription(__('Промпты определяют, как AI должен вести себя в разных сценариях.'))
            ->recordActions([
                EditAction::make()
                    ->label(__('Редактировать'))
                    ->icon(Heroicon::OutlinedPencil)
                    ->iconButton()
                    ->tooltip(__('Редактировать промпт')),
                Action::make('playground')
                    ->label(__('Проверить'))
                    ->color('info')
                    ->icon(Heroicon::OutlinedPlay)
                    ->iconButton()
                    ->tooltip(__('Проверить ответ AI'))
                    ->form([
                        Textarea::make('test_input')
                            ->label(__('Пример запроса'))
                            ->helperText(__('Можно написать обычным текстом или использовать JSON для сложного сценария.'))
                            ->rows(4)
                            ->default('{"query": "Тестовый запрос"}'),
                        Select::make('model_release_id')
                            ->label(__('Модель для проверки'))
                            ->options(fn (AiPrompt $record): array => self::modelReleaseOptions($record))
                            ->getSearchResultsUsing(fn (string $search, AiPrompt $record): array => self::modelReleaseOptions($record, $search))
                            ->getOptionLabelUsing(fn (mixed $value, AiPrompt $record): ?string => self::modelReleaseLabel($record, $value))
                            ->optionsLimit(50)
                            ->searchable()
                            ->native(false)
                            ->required(),
                    ])
                    ->action(function (AiPrompt $record, array $data, ExecutePlaygroundRun $playgroundAction): void {
                        $user = Auth::user();
                        if (! $user) {
                            return;
                        }

                        try {
                            $input = [];
                            $rawInput = trim((string) ($data['test_input'] ?? ''));
                            if (str_starts_with($rawInput, '{')) {
                                $decoded = json_decode($rawInput, true);
                                if (is_array($decoded)) {
                                    $input = $decoded;
                                }
                            } else {
                                $input = ['query' => $rawInput];
                            }

                            $result = $playgroundAction->handle(
                                actor: $user,
                                capability: $record->capability,
                                promptVersionId: $record->active_version_id,
                                modelReleaseId: (int) $data['model_release_id'],
                                inputVariables: $input,
                            );

                            if ($result->isSuccess()) {
                                $notification = Notification::make()
                                    ->title(__('Проверка успешна'))
                                    ->body(AiPlaygroundResultPresentation::body($result))
                                    ->success();
                                if ($result->runId > 0) {
                                    $notification->actions([
                                        Action::make('technicalData')
                                            ->label(__('Подробности проверки'))
                                            ->url(AiRunResource::getUrl('view', ['record' => $result->runId]))
                                            ->button()
                                            ->openUrlInNewTab(),
                                    ]);
                                }
                                $notification->send();
                            } else {
                                Notification::make()
                                    ->title(__('Не удалось проверить промпт'))
                                    ->body($result->errorMessageSanitized ?? __('Не удалось получить ответ для проверки. Проверьте настройки AI и повторите попытку.'))
                                    ->danger()
                                    ->send();
                            }
                        } catch (Throwable $exception) {
                            Notification::make()
                                ->title(__('Не удалось проверить промпт'))
                                ->body($exception instanceof \InvalidArgumentException ? $exception->getMessage() : __('Проверьте настройки и повторите попытку.'))
                                ->danger()
                                ->send();
                        }
                    }),
            ])
            ->defaultSort('updated_at', 'desc');
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('organization_id', app(OrganizationContext::class)->id())
            ->with(['activeVersion']);
    }

    public static function getRelations(): array
    {
        return [
            PromptVersionsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAiPrompts::route('/'),
            'create' => CreateAiPrompt::route('/create'),
            'edit' => EditAiPrompt::route('/{record}/edit'),
        ];
    }

    /** @return array<int|string, string> */
    public static function modelReleaseOptions(AiPrompt $prompt, string $search = ''): array
    {
        $query = AiModelRelease::query()
            ->where('organization_id', app(OrganizationContext::class)->id())
            ->whereIn('status', ['active', 'retired'])
            ->whereJsonContains('capabilities', $prompt->capability->value);

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
            ->mapWithKeys(static fn (AiModelRelease $release): array => [$release->getKey() => self::modelReleaseDisplayLabel($release)])
            ->all();
    }

    private static function modelReleaseLabel(AiPrompt $prompt, mixed $value): ?string
    {
        if (! is_scalar($value) || ! is_numeric($value)) {
            return null;
        }

        $release = AiModelRelease::query()
            ->where('organization_id', app(OrganizationContext::class)->id())
            ->whereKey((int) $value)
            ->whereJsonContains('capabilities', $prompt->capability->value)
            ->with(['modelConfiguration:id,display_name'])
            ->first(['id', 'model_config_id', 'provider_name', 'model_name', 'release_number', 'status']);

        return $release instanceof AiModelRelease
            ? self::modelReleaseDisplayLabel($release)
            : __('Сохранённая модель недоступна');
    }

    private static function modelReleaseDisplayLabel(AiModelRelease $release): string
    {
        try {
            $provider = AiProviderCatalog::label($release->provider_name);
        } catch (\InvalidArgumentException) {
            $provider = __('Провайдер требует проверки');
        }

        return __(':provider · :model · версия :version', [
            'provider' => $provider,
            'model' => $release->modelConfiguration->display_name,
            'version' => $release->release_number,
        ]);
    }
}
