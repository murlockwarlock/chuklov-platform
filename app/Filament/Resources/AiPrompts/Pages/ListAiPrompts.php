<?php

namespace App\Filament\Resources\AiPrompts\Pages;

use App\Filament\Resources\AiPrompts\AiPromptResource;
use App\Filament\Support\LocalizedListRecords;
use App\Modules\AI\Application\Actions\ImportPromptBundle;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Auth;

class ListAiPrompts extends LocalizedListRecords
{
    protected static string $resource = AiPromptResource::class;

    protected static ?string $title = 'Промпты и версии';

    public function getBreadcrumbs(): array
    {
        return [];
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
            Action::make('import_bundle')
                ->label(__('Импорт пакета (JSON)'))
                ->color('gray')
                ->form([
                    Textarea::make('bundle_json')
                        ->label(__('JSON пакета промпта'))
                        ->rows(8)
                        ->required(),
                ])
                ->action(function (array $data, ImportPromptBundle $importAction) {
                    $user = Auth::user();
                    if ($user) {
                        try {
                            $importAction->handle($user, (string) ($data['bundle_json'] ?? ''));
                            Notification::make()->title(__('Промпт успешно импортирован'))->success()->send();
                        } catch (\Throwable $e) {
                            Notification::make()->title(__('Ошибка импорта: ').$e->getMessage())->danger()->send();
                        }
                    }
                }),
        ];
    }
}
