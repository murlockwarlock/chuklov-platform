<?php

namespace App\Filament\Resources\AiPrompts\RelationManagers;

use App\Models\User;
use App\Modules\AI\Application\Actions\ActivatePromptVersion;
use App\Modules\AI\Application\Actions\CreatePromptDraft;
use App\Modules\AI\Application\Actions\RetirePromptVersion;
use App\Modules\AI\Domain\Enums\PromptVersionStatus;
use App\Modules\AI\Domain\Models\AiPrompt;
use App\Modules\AI\Domain\Models\AiPromptVersion;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
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
        return $schema
            ->components([
                Textarea::make('system_prompt')
                    ->label('Системный промпт (System Instructions)')
                    ->required()
                    ->rows(6)
                    ->columnSpanFull(),
                Textarea::make('user_prompt_template')
                    ->label('Шаблон пользовательского промпта (с переменными, например {{query}})')
                    ->required()
                    ->rows(4)
                    ->columnSpanFull(),
                TextInput::make('change_notes')
                    ->label('Описание изменений в версии')
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
                TextColumn::make('change_notes')->label('Заметки к версии')->placeholder('—'),
                TextColumn::make('activated_at')->label('Активирована')->dateTime('d.m.Y H:i')->placeholder('—'),
                TextColumn::make('created_at')->label('Создана')->dateTime('d.m.Y H:i'),
            ])
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
