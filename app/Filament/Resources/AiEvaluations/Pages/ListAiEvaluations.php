<?php

namespace App\Filament\Resources\AiEvaluations\Pages;

use App\Filament\Resources\AiEvaluations\AiEvaluationResource;
use App\Models\User;
use App\Modules\AI\Application\Actions\MaterializeSourceBackedEvaluations;
use App\Modules\AI\Domain\Models\AiEvalSuite;
use App\Modules\Organizations\Application\OrganizationContext;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Section;
use Illuminate\Support\Facades\Auth;
use Throwable;

class ListAiEvaluations extends ListRecords
{
    protected static string $resource = AiEvaluationResource::class;

    protected static ?string $title = 'Проверки AI';

    public function getBreadcrumbs(): array
    {
        return [];
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
            Action::make('materialize_source_backed')
                ->label('Установить стандартные тестовые сценарии')
                ->color('gray')
                ->visible(fn (): bool => AiEvaluationResource::canCreate() && ! AiEvalSuite::query()
                    ->where('organization_id', app(OrganizationContext::class)->id())
                    ->where('key', 'like', 'source_agent_%')
                    ->exists())
                ->requiresConfirmation()
                ->modalHeading('Установить стандартные тестовые сценарии')
                ->modalDescription('Добавит готовые обезличенные примеры для проверки AI. Реальные данные клиентов не используются.')
                ->form([
                    Section::make('Дополнительные настройки')
                        ->collapsed()
                        ->schema([
                            Toggle::make('activate_prompt_versions')
                                ->label('Использовать исходные версии для проверки')
                                ->helperText('Используйте только в тестовом окружении после проверки промптов и выбора модели.')
                                ->default(false),
                        ]),
                ])
                ->action(function (array $data, MaterializeSourceBackedEvaluations $materializer): void {
                    $actor = Auth::user();
                    if (! $actor instanceof User) {
                        return;
                    }

                    try {
                        $summary = $materializer->handle($actor, (bool) ($data['activate_prompt_versions'] ?? false));
                        Notification::make()
                            ->title('Стандартные тестовые сценарии установлены')
                            ->body(sprintf(
                                'Наборы: %d · примеры: %d · новые версии промптов: %d',
                                $summary['suites_created'],
                                $summary['cases_created'],
                                $summary['prompt_versions_created'],
                            ))
                            ->success()
                            ->send();
                    } catch (Throwable $exception) {
                        Notification::make()
                            ->title('Не удалось установить тестовые сценарии')
                            ->body($exception instanceof \InvalidArgumentException ? $exception->getMessage() : 'Проверьте настройки и повторите попытку.')
                            ->danger()
                            ->send();
                    }
                }),
        ];
    }
}
