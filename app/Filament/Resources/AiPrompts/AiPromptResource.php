<?php

namespace App\Filament\Resources\AiPrompts;

use App\Filament\Resources\AiPrompts\Pages\CreateAiPrompt;
use App\Filament\Resources\AiPrompts\Pages\EditAiPrompt;
use App\Filament\Resources\AiPrompts\Pages\ListAiPrompts;
use App\Filament\Resources\AiPrompts\RelationManagers\PromptVersionsRelationManager;
use App\Filament\Resources\AiPrompts\Schemas\AiPromptForm;
use App\Modules\AI\Application\Actions\ExecutePlaygroundRun;
use App\Modules\AI\Domain\Enums\AiCapability;
use App\Modules\AI\Domain\Models\AiPrompt;
use App\Modules\Organizations\Application\OrganizationContext;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

final class AiPromptResource extends Resource
{
    protected static ?string $model = AiPrompt::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSparkles;

    protected static ?string $navigationLabel = 'Промпты и версии';

    protected static string|\UnitEnum|null $navigationGroup = 'Искусственный интеллект';

    protected static ?int $navigationSort = 3;

    protected static ?string $modelLabel = 'промпт';

    protected static ?string $pluralModelLabel = 'промпты';

    public static function form(Schema $schema): Schema
    {
        return AiPromptForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label('Название')->searchable()->sortable(),
                TextColumn::make('key')->label('Ключ')->searchable()->sortable(),
                TextColumn::make('capability')
                    ->label('Возможность')
                    ->formatStateUsing(fn ($state) => $state instanceof AiCapability ? $state->label() : (string) $state),
                TextColumn::make('activeVersion.version')->label('Активная версия')->placeholder('Нет активной'),
                TextColumn::make('versions_count')->counts('versions')->label('Всего версий'),
                TextColumn::make('updated_at')->label('Изменен')->dateTime('d.m.Y H:i')->sortable(),
            ])
            ->recordActions([
                EditAction::make(),
                Action::make('playground')
                    ->label('Песочница')
                    ->color('info')
                    ->icon(Heroicon::OutlinedPlay)
                    ->form([
                        Textarea::make('test_input')
                            ->label('Тестовый ввод (JSON или текст)')
                            ->rows(4)
                            ->default('{"query": "Тестовый запрос"}'),
                    ])
                    ->action(function (AiPrompt $record, array $data, ExecutePlaygroundRun $playgroundAction) {
                        $user = Auth::user();
                        if (! $user) {
                            return;
                        }

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
                            inputVariables: $input,
                        );

                        if ($result->isSuccess()) {
                            Notification::make()
                                ->title('Успешный запуск в песочнице')
                                ->body("Ответ ({$result->latencyMs}мс): ".($result->outputText ?: 'OK'))
                                ->success()
                                ->send();
                        } else {
                            Notification::make()
                                ->title('Ошибка выполнения в песочнице')
                                ->body($result->errorMessageSanitized ?? 'Неизвестная ошибка')
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
}
