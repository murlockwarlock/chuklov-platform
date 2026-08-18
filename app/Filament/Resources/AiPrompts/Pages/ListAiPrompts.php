<?php

namespace App\Filament\Resources\AiPrompts\Pages;

use App\Filament\Resources\AiPrompts\AiPromptResource;
use App\Modules\AI\Application\Actions\ImportPromptBundle;
use App\Modules\Organizations\Application\OrganizationContext;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Facades\Auth;

class ListAiPrompts extends ListRecords
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
            CreateAction::make()
                ->mutateFormDataUsing(function (array $data): array {
                    $data['organization_id'] = app(OrganizationContext::class)->id();

                    return $data;
                }),
            Action::make('import_bundle')
                ->label('Импорт пакета (JSON)')
                ->color('gray')
                ->form([
                    Textarea::make('bundle_json')
                        ->label('JSON пакета промпта')
                        ->rows(8)
                        ->required(),
                ])
                ->action(function (array $data, ImportPromptBundle $importAction) {
                    $user = Auth::user();
                    if ($user) {
                        try {
                            $importAction->handle($user, (string) ($data['bundle_json'] ?? ''));
                            Notification::make()->title('Промпт успешно импортирован')->success()->send();
                        } catch (\Throwable $e) {
                            Notification::make()->title('Ошибка импорта: '.$e->getMessage())->danger()->send();
                        }
                    }
                }),
        ];
    }
}
