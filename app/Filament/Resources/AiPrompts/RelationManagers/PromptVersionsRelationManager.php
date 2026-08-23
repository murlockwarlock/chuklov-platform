<?php

namespace App\Filament\Resources\AiPrompts\RelationManagers;

use App\Models\User;
use App\Modules\AI\Application\Actions\ActivatePromptVersion;
use App\Modules\AI\Application\Actions\CreatePromptDraft;
use App\Modules\AI\Application\Actions\RetirePromptVersion;
use App\Modules\AI\Domain\Enums\PromptVersionStatus;
use App\Modules\AI\Domain\Models\AiPrompt;
use App\Modules\AI\Domain\Models\AiPromptVersion;
use App\Modules\AI\Domain\ValueObjects\AiParameterConfig;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

class PromptVersionsRelationManager extends RelationManager
{
    protected static string $relationship = 'versions';

    protected static ?string $title = 'Версии промпта';

    protected static string|BackedEnum|null $icon = Heroicon::OutlinedDocumentDuplicate;

    public function form(Schema $schema): Schema
    {
        /** @var AiPrompt $prompt */
        $prompt = $this->getOwnerRecord();
        $latestVersion = $prompt->latestVersion()->first();
        $parameters = AiParameterConfig::fromArray((array) data_get($latestVersion, 'parameter_config', []));

        return $schema
            ->components([
                Textarea::make('system_prompt')
                    ->label('Системные инструкции / поведение AI')
                    ->helperText('Опишите, как AI должен рассуждать, что учитывать и каких ошибок избегать.')
                    ->default($latestVersion?->system_prompt)
                    ->required()
                    ->rows(6)
                    ->columnSpanFull(),
                Textarea::make('user_prompt_template')
                    ->label('Шаблон запроса')
                    ->helperText('Используйте переменные текущей версии, например {{query}}.')
                    ->default($latestVersion?->user_prompt_template)
                    ->required()
                    ->rows(4)
                    ->columnSpanFull(),
                Section::make('Настройки ответа')
                    ->description('Эти настройки определяют стиль и максимальный объём ответа AI.')
                    ->schema([
                        TextInput::make('temperature')
                            ->label('Креативность')
                            ->helperText('Низкое значение делает ответы стабильнее, высокое — разнообразнее.')
                            ->numeric()
                            ->minValue(0)
                            ->maxValue(2)
                            ->default($parameters->temperature)
                            ->required(),
                        TextInput::make('max_tokens')
                            ->label('Максимальная длина ответа')
                            ->helperText('Внутренний предел длины ответа AI.')
                            ->numeric()
                            ->minValue(1)
                            ->maxValue(8192)
                            ->default($parameters->maxTokens)
                            ->required(),
                    ])
                    ->columns(2)
                    ->columnSpanFull(),
                Section::make('Дополнительные настройки')
                    ->description('Точные параметры для случаев, когда стандартных настроек недостаточно. Остальные схемы версии сохраняются автоматически.')
                    ->collapsed()
                    ->schema([
                        TextInput::make('top_p')
                            ->label('Top P — точная настройка')
                            ->numeric()
                            ->minValue(0)
                            ->maxValue(1)
                            ->default($parameters->topP)
                            ->required(),
                        TextInput::make('frequency_penalty')
                            ->label('Штраф за повторение')
                            ->numeric()
                            ->minValue(-2)
                            ->maxValue(2)
                            ->default($parameters->frequencyPenalty)
                            ->required(),
                        TextInput::make('presence_penalty')
                            ->label('Штраф за однообразие')
                            ->numeric()
                            ->minValue(-2)
                            ->maxValue(2)
                            ->default($parameters->presencePenalty)
                            ->required(),
                        TextInput::make('timeout_seconds')
                            ->label('Время ожидания ответа, секунд')
                            ->numeric()
                            ->minValue(1)
                            ->maxValue(120)
                            ->default($parameters->timeoutSeconds)
                            ->required(),
                        TextInput::make('change_notes')
                            ->label('Что изменилось')
                            ->columnSpanFull(),
                    ])
                    ->columns(2)
                    ->columnSpanFull(),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('version')->label('Версия')->sortable(),
                TextColumn::make('status')
                    ->label('Статус')
                    ->badge()
                    ->color(fn ($state): string => match ($state instanceof PromptVersionStatus ? $state->value : (string) $state) {
                        'active' => 'success',
                        'draft' => 'warning',
                        'retired' => 'gray',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn ($state) => $state instanceof PromptVersionStatus ? $state->label() : (string) $state),
                TextColumn::make('change_notes')->label('Что изменилось')->placeholder('—'),
                TextColumn::make('parameter_config')
                    ->label('Ответ')
                    ->formatStateUsing(function ($state): string {
                        $parameters = AiParameterConfig::fromArray((array) $state);

                        return 'Креативность '.$parameters->temperature.' · до '.$parameters->maxTokens.' токенов';
                    }),
                TextColumn::make('activated_at')->label('Активирована')->dateTime('d.m.Y H:i')->placeholder('—'),
                TextColumn::make('created_at')->label('Создана')->dateTime('d.m.Y H:i'),
            ])
            ->emptyStateHeading('Версий пока нет')
            ->emptyStateDescription('Создайте первую версию, чтобы задать инструкции AI и настройки ответа.')
            ->headerActions([
                CreateAction::make()
                    ->label('Создать новую версию (черновик)')
                    ->using(function (array $data, CreatePromptDraft $createAction): AiPromptVersion {
                        $user = Auth::user();
                        abort_unless($user instanceof User, 403);
                        /** @var AiPrompt $prompt */
                        $prompt = $this->getOwnerRecord();

                        return $createAction->handle($user, $prompt->id, $data);
                    }),
            ])
            ->recordActions([
                Action::make('activate')
                    ->label('Сделать активной')
                    ->color('success')
                    ->visible(fn (AiPromptVersion $record) => $record->status !== PromptVersionStatus::Active)
                    ->requiresConfirmation()
                    ->action(function (AiPromptVersion $record, ActivatePromptVersion $activateAction) {
                        $user = Auth::user();
                        if ($user) {
                            $activateAction->handle($user, $record->id);
                            Notification::make()->title('Версия промпта активирована')->success()->send();
                        }
                    }),
                Action::make('retire')
                    ->label('В архив')
                    ->color('gray')
                    ->visible(fn (AiPromptVersion $record) => $record->status === PromptVersionStatus::Active)
                    ->requiresConfirmation()
                    ->action(function (AiPromptVersion $record, RetirePromptVersion $retireAction) {
                        $user = Auth::user();
                        if ($user) {
                            $retireAction->handle($user, $record->id);
                            Notification::make()->title('Версия отправлена в архив')->success()->send();
                        }
                    }),
            ])
            ->defaultSort('version', 'desc');
    }
}
