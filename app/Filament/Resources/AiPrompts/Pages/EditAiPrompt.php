<?php

namespace App\Filament\Resources\AiPrompts\Pages;

use App\Filament\Resources\AiPrompts\AiPromptResource;
use App\Models\User;
use App\Modules\AI\Application\Actions\ExportPromptBundle;
use App\Modules\AI\Application\Actions\UpdateAiPrompt;
use App\Modules\AI\Domain\Models\AiPrompt;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class EditAiPrompt extends EditRecord
{
    protected static string $resource = AiPromptResource::class;

    protected static ?string $title = 'Редактировать промпт';

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        abort_unless($record instanceof AiPrompt, 404);

        $actor = auth()->user();
        abort_unless($actor instanceof User, 403);

        return app(UpdateAiPrompt::class)->handle($actor, $record, $data);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('export_active_bundle')
                ->label('Экспорт активной версии (JSON)')
                ->color('gray')
                ->visible(fn (AiPrompt $record) => $record->active_version_id !== null)
                ->action(function (AiPrompt $record, ExportPromptBundle $exportAction) {
                    $user = Auth::user();
                    if ($user && $record->active_version_id !== null) {
                        $bundle = $exportAction->handle($user, $record->active_version_id);
                        $json = json_encode($bundle->toArray(), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
                        Notification::make()
                            ->title('Пакет сформирован')
                            ->body(is_string($json) ? $json : '')
                            ->success()
                            ->send();
                    }
                }),
        ];
    }
}
