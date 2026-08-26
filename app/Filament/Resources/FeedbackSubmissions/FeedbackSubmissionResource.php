<?php

namespace App\Filament\Resources\FeedbackSubmissions;

use App\Filament\Resources\FeedbackSubmissions\Pages\ListFeedbackSubmissions;
use App\Filament\Resources\FeedbackSubmissions\Pages\ViewFeedbackSubmission;
use App\Models\User;
use App\Modules\Feedback\Application\ListFeedbackSubmissionsForCrm;
use App\Modules\Feedback\Domain\Models\FeedbackSubmission;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/** @extends resource<FeedbackSubmission> */
final class FeedbackSubmissionResource extends Resource
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
        return $table
            ->stackedOnMobile()
            ->columns([
                TextColumn::make('client.full_name')->label('Клиент')->searchable()->wrap(),
                TextColumn::make('score')->label('Оценка')->badge()->sortable(),
                TextColumn::make('source')->label('Источник')->wrap(),
                TextColumn::make('internal_feedback_present')
                    ->label('Внутренний текст')
                    ->state(fn (FeedbackSubmission $record): string => $record->getRawOriginal('internal_feedback') === null ? 'Нет' : 'Есть')
                    ->badge()
                    ->color(fn (string $state): string => $state === 'Есть' ? 'warning' : 'gray'),
                TextColumn::make('submitted_at')->label('Отправлено')->dateTime('d.m.Y H:i')->sortable(),
            ])
            ->recordActions([
                ViewAction::make()->label('Открыть'),
            ])
            ->defaultSort('submitted_at', 'desc')
            ->paginated([10, 25, 50]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Структурные данные')
                ->schema([
                    TextEntry::make('client.full_name')->label('Клиент'),
                    TextEntry::make('score')->label('Оценка'),
                    TextEntry::make('source')->label('Источник'),
                    TextEntry::make('submitted_at')->label('Отправлено')->dateTime('d.m.Y H:i'),
                ])
                ->columns(2),
            Section::make('Внутренняя обратная связь')
                ->description('Текст доступен только в авторизованном просмотре клиента и не участвует в поиске или аудите.')
                ->schema([
                    TextEntry::make('internal_feedback')
                        ->label('Текст')
                        ->placeholder('Текст не оставлен')
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
