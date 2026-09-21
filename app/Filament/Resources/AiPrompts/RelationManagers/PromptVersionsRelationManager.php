<?php

namespace App\Filament\Resources\AiPrompts\RelationManagers;

use App\Filament\Support\AiPromptTextSections;
use App\Filament\Support\CrmLabel;
use App\Filament\Support\LocalizedRelationManager;
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
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

class PromptVersionsRelationManager extends LocalizedRelationManager
{
    protected static string $relationship = 'versions';

    protected static ?string $title = 'История версий';

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
                    ->label(__('Полный исходный prompt'))
                    ->helperText(__('Редактируется весь исходный текст версии, включая блоки [SOURCE TEXT] и [PLATFORM SAFETY GUARDRAIL].'))
                    ->default($latestVersion?->system_prompt)
                    ->required()
                    ->live(debounce: 500)
                    ->rows(18)
                    ->columnSpanFull(),
                Section::make(__('Границы инструкции'))
                    ->description(__('Проверяйте исходные рабочие инструкции и обязательные safety guardrails отдельно. Текст выше остаётся полным и редактируемым.'))
                    ->schema([
                        Placeholder::make('source_text_preview')
                            ->label('SOURCE TEXT')
                            ->content(fn (Get $get): string => AiPromptTextSections::source((string) $get('system_prompt'))),
                        Placeholder::make('guardrails_preview')
                            ->label(__('Safety guardrails платформы'))
                            ->content(fn (Get $get): string => AiPromptTextSections::guardrails((string) $get('system_prompt'))),
                        Placeholder::make('runtime_contract_preview')
                            ->label(__('Runtime-контракт'))
                            ->content(fn (Get $get): string => AiPromptTextSections::runtimeContract((string) $get('system_prompt'))),
                    ])
                    ->columns(3)
                    ->columnSpanFull(),
                Textarea::make('user_prompt_template')
                    ->label(__('Шаблон запроса'))
                    ->helperText(__('Используйте переменные текущей версии, например {{query}}.'))
                    ->default($latestVersion?->user_prompt_template)
                    ->required()
                    ->rows(4)
                    ->columnSpanFull(),
                Section::make(__('Настройки ответа'))
                    ->description(__('Эти настройки определяют стиль и максимальный объём ответа AI.'))
                    ->schema([
                        TextInput::make('temperature')
                            ->label(__('Креативность'))
                            ->helperText(__('Низкое значение делает ответы стабильнее, высокое — разнообразнее.'))
                            ->numeric()
                            ->minValue(0)
                            ->maxValue(2)
                            ->default($parameters->temperature),
                        TextInput::make('max_tokens')
                            ->label(__('Максимальная длина ответа'))
                            ->helperText(__('Внутренний предел длины ответа AI.'))
                            ->numeric()
                            ->minValue(1)
                            ->maxValue(8192)
                            ->default($parameters->maxTokens),
                    ])
                    ->columns(2)
                    ->columnSpanFull(),
                Section::make(__('Дополнительные настройки'))
                    ->description(__('Точные параметры для случаев, когда стандартных настроек недостаточно. Остальные схемы версии сохраняются автоматически.'))
                    ->collapsed()
                    ->schema([
                        TextInput::make('top_p')
                            ->label(__('Top P — точная настройка'))
                            ->helperText(__('Необязательный параметр. Учитывается только при поддержке выбранной моделью.'))
                            ->numeric()
                            ->minValue(0)
                            ->maxValue(1)
                            ->default(null),
                        TextInput::make('frequency_penalty')
                            ->label(__('Штраф за повторение'))
                            ->numeric()
                            ->minValue(-2)
                            ->maxValue(2)
                            ->default(null),
                        TextInput::make('presence_penalty')
                            ->label(__('Штраф за однообразие'))
                            ->numeric()
                            ->minValue(-2)
                            ->maxValue(2)
                            ->default(null),
                        TextInput::make('timeout_seconds')
                            ->label(__('Время ожидания ответа, секунд'))
                            ->numeric()
                            ->minValue(1)
                            ->maxValue(120)
                            ->default($parameters->timeoutSeconds),
                        TextInput::make('change_notes')
                            ->label(__('Что изменилось'))
                            ->columnSpanFull(),
                    ])
                    ->columns(2)
                    ->columnSpanFull(),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->stackedOnMobile()
            ->columns([
                TextColumn::make('version')->label(__('Версия'))->sortable(),
                TextColumn::make('status')
                    ->label(__('Статус'))
                    ->badge()
                    ->color(fn ($state): string => match ($state instanceof PromptVersionStatus ? $state->value : (string) $state) {
                        'active' => 'success',
                        'draft' => 'warning',
                        'retired' => 'gray',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn ($state) => $state instanceof PromptVersionStatus ? CrmLabel::enum($state) : (string) $state),
                TextColumn::make('change_notes')->label(__('Что изменилось'))->placeholder('—'),
                TextColumn::make('created_at')->label(__('Дата'))->dateTime('d.m.Y H:i'),
            ])
            ->emptyStateHeading(__('Версий пока нет'))
            ->emptyStateDescription(__('Создайте первую версию, чтобы задать инструкции AI и настройки ответа.'))
            ->headerActions([
                CreateAction::make()
                    ->label(__('Создать новую версию (черновик)'))
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
                    ->label(__('Сделать активной'))
                    ->color('success')
                    ->visible(fn (AiPromptVersion $record) => $record->status !== PromptVersionStatus::Active)
                    ->requiresConfirmation()
                    ->action(function (AiPromptVersion $record, ActivatePromptVersion $activateAction) {
                        $user = Auth::user();
                        if ($user) {
                            $activateAction->handle($user, $record->id);
                            Notification::make()->title(__('Версия промпта активирована'))->success()->send();
                        }
                    }),
                Action::make('retire')
                    ->label(__('В архив'))
                    ->color('gray')
                    ->visible(fn (AiPromptVersion $record) => $record->status === PromptVersionStatus::Active)
                    ->requiresConfirmation()
                    ->action(function (AiPromptVersion $record, RetirePromptVersion $retireAction) {
                        $user = Auth::user();
                        if ($user) {
                            $retireAction->handle($user, $record->id);
                            Notification::make()->title(__('Версия отправлена в архив'))->success()->send();
                        }
                    }),
            ])
            ->defaultSort('version', 'desc');
    }
}
