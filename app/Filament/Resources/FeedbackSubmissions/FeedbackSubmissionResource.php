<?php

namespace App\Filament\Resources\FeedbackSubmissions;

use App\Filament\Resources\Clients\ClientResource;
use App\Filament\Resources\FeedbackSubmissions\Pages\ListFeedbackSubmissions;
use App\Filament\Resources\FeedbackSubmissions\Pages\ViewFeedbackSubmission;
use App\Filament\Support\CrmEntityLinks;
use App\Filament\Support\LocalizedResource;
use App\Models\User;
use App\Modules\Analytics\Application\AcceptAnalyticsClassification;
use App\Modules\Analytics\Application\SuggestAnalyticsTags;
use App\Modules\Analytics\Domain\Enums\ClassificationRunStatus;
use App\Modules\Analytics\Domain\Models\AnalyticsClassificationRun;
use App\Modules\Feedback\Application\ClassifyFeedbackSubmission;
use App\Modules\Feedback\Application\FeedbackThemeCatalog;
use App\Modules\Feedback\Application\ListFeedbackSubmissionsForCrm;
use App\Modules\Feedback\Domain\Models\FeedbackSubmission;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/** @extends resource<FeedbackSubmission> */
final class FeedbackSubmissionResource extends LocalizedResource
{
    protected static ?string $model = FeedbackSubmission::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChatBubbleBottomCenterText;

    protected static ?string $navigationLabel = 'Обратная связь';

    protected static string|\UnitEnum|null $navigationGroup = 'Клиенты';

    protected static ?int $navigationSort = 4;

    protected static ?string $modelLabel = 'отзыв';

    protected static ?string $pluralModelLabel = 'обратная связь';

    public static function canAccess(): bool
    {
        $actor = auth()->user();

        return $actor instanceof User && app(OrganizationAuthorizer::class)->allows(
            $actor,
            app(OrganizationContext::class)->organization(),
            OrganizationPermission::ViewClients,
        );
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function table(Table $table): Table
    {
        $canViewClients = ClientResource::canViewAny();

        return $table
            ->stackedOnMobile()
            ->columns([
                TextColumn::make('client.full_name')
                    ->label(__('Клиент'))
                    ->searchable()
                    ->wrap()
                    ->url(fn (FeedbackSubmission $record): ?string => CrmEntityLinks::clientUrl($record->client, $canViewClients))
                    ->color(fn (FeedbackSubmission $record): ?string => CrmEntityLinks::clientUrl($record->client, $canViewClients) === null ? null : 'primary')
                    ->disabledClick(fn (FeedbackSubmission $record): bool => CrmEntityLinks::clientUrl($record->client, $canViewClients) === null),
                TextColumn::make('score')->label(__('Оценка'))->badge()->sortable(),
                TextColumn::make('source')->label(__('Источник'))->wrap(),
                TextColumn::make('themes.name')
                    ->label(__('Темы'))
                    ->listWithLineBreaks()
                    ->wrap(),
                TextColumn::make('internal_feedback_present')
                    ->label(__('Внутренний текст'))
                    ->state(fn (FeedbackSubmission $record): string => $record->getRawOriginal('internal_feedback') === null ? __('Нет') : __('Есть'))
                    ->badge()
                    ->color(fn (string $state): string => $state === __('Есть') ? 'warning' : 'gray'),
                TextColumn::make('submitted_at')->label(__('Отправлено'))->dateTime('d.m.Y H:i')->sortable(),
            ])
            ->recordActions([
                ViewAction::make()->label(__('Открыть')),
                Action::make('classify')
                    ->label(__('Классифицировать'))
                    ->icon('heroicon-o-tag')
                    ->schema([
                        Select::make('theme_ids')
                            ->label(__('Темы комментария'))
                            ->options(fn (): array => app(FeedbackThemeCatalog::class)->options(app(OrganizationContext::class)->id()))
                            ->multiple()
                            ->searchable()
                            ->preload(),
                    ])
                    ->fillForm(fn (FeedbackSubmission $record): array => ['theme_ids' => $record->themes()->pluck('feedback_themes.id')->all()])
                    ->visible(fn (): bool => auth()->user() instanceof User && app(OrganizationAuthorizer::class)->allows(
                        auth()->user(),
                        app(OrganizationContext::class)->organization(),
                        OrganizationPermission::ManageClients,
                    ))
                    ->action(function (FeedbackSubmission $record, array $data): void {
                        $actor = auth()->user();
                        abort_unless($actor instanceof User, 403);
                        app(ClassifyFeedbackSubmission::class)->handle(
                            $actor,
                            $record,
                            is_array($data['theme_ids'] ?? null) ? $data['theme_ids'] : [],
                        );
                    }),
                Action::make('suggestAi')
                    ->label(__('Предложить темы с ИИ'))
                    ->icon('heroicon-o-sparkles')
                    ->visible(fn (): bool => auth()->user() instanceof User && app(OrganizationAuthorizer::class)->allows(
                        auth()->user(),
                        app(OrganizationContext::class)->organization(),
                        OrganizationPermission::ManageClients,
                    ))
                    ->action(function (FeedbackSubmission $record): void {
                        $actor = auth()->user();
                        abort_unless($actor instanceof User, 403);
                        $options = app(FeedbackThemeCatalog::class)->options(app(OrganizationContext::class)->id());
                        $result = app(SuggestAnalyticsTags::class)->handle(
                            actor: $actor,
                            taxonomy: 'feedback',
                            sourceType: 'feedback_submission',
                            sourceId: (int) $record->getKey(),
                            clientId: (int) $record->client_id,
                            sourceText: (string) ($record->internal_feedback ?? ''),
                            availableTags: array_map(
                                static fn (int|string $id, string $label): array => ['id' => (int) $id, 'label' => $label],
                                array_keys($options),
                                array_values($options),
                            ),
                            force: true,
                        );
                        $labels = collect($result->suggestions)->pluck('label')->implode(', ');
                        if ($labels !== '') {
                            Notification::make()->success()->title(__('Предложения ИИ готовы'))->body($labels)->send();

                            return;
                        }

                        Notification::make()->warning()->title(__('Предложения ИИ недоступны'))->body(__('Настройте prompt классификатора или добавьте структурированные темы.'))->send();
                    }),
                Action::make('acceptAi')
                    ->label(__('Принять предложения ИИ'))
                    ->icon('heroicon-o-check-circle')
                    ->schema([
                        Select::make('theme_ids')
                            ->label(__('Темы комментария'))
                            ->options(fn (): array => app(FeedbackThemeCatalog::class)->options(app(OrganizationContext::class)->id()))
                            ->multiple()
                            ->searchable()
                            ->preload(),
                    ])
                    ->fillForm(function (FeedbackSubmission $record): array {
                        $run = AnalyticsClassificationRun::query()
                            ->where('organization_id', app(OrganizationContext::class)->id())
                            ->where('taxonomy', 'feedback')
                            ->where('source_type', 'feedback_submission')
                            ->where('source_id', $record->getKey())
                            ->where('status', ClassificationRunStatus::NeedsReview)
                            ->latest('id')
                            ->first();

                        return [
                            'theme_ids' => collect($run?->suggestions ?? [])->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all(),
                        ];
                    })
                    ->visible(function (FeedbackSubmission $record): bool {
                        if (! auth()->user() instanceof User || ! app(OrganizationAuthorizer::class)->allows(
                            auth()->user(),
                            app(OrganizationContext::class)->organization(),
                            OrganizationPermission::ManageClients,
                        )) {
                            return false;
                        }

                        return AnalyticsClassificationRun::query()
                            ->where('organization_id', app(OrganizationContext::class)->id())
                            ->where('taxonomy', 'feedback')
                            ->where('source_type', 'feedback_submission')
                            ->where('source_id', $record->getKey())
                            ->where('status', ClassificationRunStatus::NeedsReview)
                            ->exists();
                    })
                    ->action(function (FeedbackSubmission $record, array $data): void {
                        $actor = auth()->user();
                        abort_unless($actor instanceof User, 403);
                        app(AcceptAnalyticsClassification::class)->handle(
                            actor: $actor,
                            runId: (int) AnalyticsClassificationRun::query()
                                ->where('organization_id', app(OrganizationContext::class)->id())
                                ->where('taxonomy', 'feedback')
                                ->where('source_type', 'feedback_submission')
                                ->where('source_id', $record->getKey())
                                ->where('status', ClassificationRunStatus::NeedsReview)
                                ->latest('id')
                                ->value('id'),
                            themeIds: is_array($data['theme_ids'] ?? null) ? $data['theme_ids'] : [],
                        );
                        Notification::make()->success()->title(__('Темы сохранены'))->send();
                    }),
            ])
            ->defaultSort('submitted_at', 'desc')
            ->paginated([10, 25, 50]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('Структурные данные'))
                ->schema([
                    TextEntry::make('client.full_name')
                        ->label(__('Клиент'))
                        ->url(fn (FeedbackSubmission $record): ?string => CrmEntityLinks::clientUrl($record->client))
                        ->color(fn (FeedbackSubmission $record): ?string => CrmEntityLinks::clientUrl($record->client) === null ? null : 'primary'),
                    TextEntry::make('score')->label(__('Оценка')),
                    TextEntry::make('source')->label(__('Источник')),
                    TextEntry::make('themes.name')->label(__('Темы'))->listWithLineBreaks()->placeholder(__('Не указаны')),
                    TextEntry::make('submitted_at')->label(__('Отправлено'))->dateTime('d.m.Y H:i'),
                ])
                ->columns(2),
            Section::make(__('Комментарий клиента'))
                ->description(__('Комментарий виден только сотрудникам с доступом к клиенту.'))
                ->schema([
                    TextEntry::make('internal_feedback')
                        ->label(__('Комментарий'))
                        ->placeholder(__('Текст не оставлен'))
                        ->columnSpanFull()
                        ->wrap(),
                ]),
        ]);
    }

    public static function getEloquentQuery(): Builder
    {
        $actor = auth()->user();
        abort_unless($actor instanceof User, 403);

        return app(ListFeedbackSubmissionsForCrm::class)->query($actor);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListFeedbackSubmissions::route('/'),
            'view' => ViewFeedbackSubmission::route('/{record}'),
        ];
    }
}
