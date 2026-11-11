<?php

namespace App\Filament\Resources\SurveyDefinitions;

use App\Filament\Resources\SurveyDefinitions\Pages\CreateSurveyDefinition;
use App\Filament\Resources\SurveyDefinitions\Pages\EditSurveyDefinition;
use App\Filament\Resources\SurveyDefinitions\Pages\ListSurveyDefinitions;
use App\Filament\Resources\SurveyDefinitions\Schemas\SurveyDefinitionForm;
use App\Filament\Support\LocalizedResource;
use App\Models\User;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Surveys\Application\UpdateSurveyPresentation;
use App\Modules\Surveys\Domain\Models\SurveyDefinition;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

final class SurveyDefinitionResource extends LocalizedResource
{
    protected static ?string $model = SurveyDefinition::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentCheck;

    protected static ?string $navigationLabel = 'Опросники и тесты';

    protected static string|\UnitEnum|null $navigationGroup = 'Контент и знания';

    protected static ?int $navigationSort = 2;

    protected static ?string $modelLabel = 'тест';

    protected static ?string $pluralModelLabel = 'тесты';

    protected static ?string $breadcrumb = 'Опросники и тесты';

    protected static ?string $recordTitleAttribute = 'title';

    public static function form(Schema $schema): Schema
    {
        return SurveyDefinitionForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return $table->stackedOnMobile()->columns([
            TextColumn::make('title')->label(__('Название'))->searchable()->sortable(),
            TextColumn::make('activeVersion.version')->label(__('Опубликованная версия'))->placeholder(__('Нет')),
            TextColumn::make('versions_count')->label(__('Всего версий')),
            IconColumn::make('is_available')->label(__('Доступен'))->boolean(),
        ])
            ->emptyStateHeading(__('Опросников пока нет'))
            ->emptyStateDescription(__('Создайте психологический опросник или тест для оценки динамики состояния клиентов.'))
            ->recordActions([
                Action::make('presentation')
                    ->label(__('Название и доступ'))
                    ->modalDescription(__('Изменяет название в CRM и доступность опросника. Вопросы, подсчёт результатов и история версий сохраняются. Включение не публикует черновик.'))
                    ->authorize(fn (SurveyDefinition $record): bool => self::canEdit($record))
                    ->fillForm(fn (SurveyDefinition $record): array => [
                        'title' => $record->title,
                        'is_available' => $record->is_available,
                        'expected_snapshot' => app(UpdateSurveyPresentation::class)->snapshot($record),
                    ])
                    ->schema([
                        TextInput::make('title')->label(__('Название в CRM'))->required()->maxLength(200),
                        Toggle::make('is_available')->label(__('Доступен клиентам после публикации')),
                        Hidden::make('expected_snapshot')->required(),
                    ])
                    ->action(function (SurveyDefinition $record, array $data): void {
                        $actor = auth()->user();
                        abort_unless($actor instanceof User, 403);
                        app(UpdateSurveyPresentation::class)->handle($actor, $record, $data['title'], (bool) $data['is_available'], $data['expected_snapshot']);
                        Notification::make()->title(__('Название и доступ сохранены'))->success()->send();
                    }),
                Action::make('hide')
                    ->label(__('Скрыть из доступа'))
                    ->icon(Heroicon::OutlinedEyeSlash)
                    ->color('warning')
                    ->requiresConfirmation()
                    ->modalHeading(__('Скрыть опросник из доступа?'))
                    ->modalDescription(__('Новые прохождения будут недоступны. Вопросы, результаты и история уже начатых прохождений сохранятся.'))
                    ->visible(fn (SurveyDefinition $record): bool => $record->is_available && self::canEdit($record))
                    ->action(fn (SurveyDefinition $record): SurveyDefinition => self::setAvailability($record, false)),
                Action::make('restore')
                    ->label(__('Вернуть в доступ'))
                    ->icon(Heroicon::OutlinedEye)
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalHeading(__('Вернуть опросник в доступ?'))
                    ->modalDescription(__('Опубликованная версия снова станет доступна для новых прохождений. История уже начатых прохождений сохранится.'))
                    ->visible(fn (SurveyDefinition $record): bool => ! $record->is_available && self::canEdit($record))
                    ->action(fn (SurveyDefinition $record): SurveyDefinition => self::setAvailability($record, true)),
                EditAction::make(),
            ])->defaultSort('title');
    }

    private static function setAvailability(SurveyDefinition $record, bool $available): SurveyDefinition
    {
        $actor = auth()->user();
        abort_unless($actor instanceof User, 403);

        $presentation = app(UpdateSurveyPresentation::class);

        return $presentation->handle(
            $actor,
            $record,
            $record->title,
            $available,
            $presentation->snapshot($record),
        );
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('organization_id', app(OrganizationContext::class)->id())->with('activeVersion')->withCount('versions');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSurveyDefinitions::route('/'),
            'create' => CreateSurveyDefinition::route('/create'),
            'edit' => EditSurveyDefinition::route('/{record}/edit'),
        ];
    }
}
