<?php

namespace App\Filament\Resources\AiEvaluations\Pages;

use App\Filament\Resources\AiEvaluations\AiEvaluationResource;
use App\Models\User;
use App\Modules\AI\Application\Actions\MaterializeSourceBackedEvaluations;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
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
                ->label('Загрузить демонстрационные проверки')
                ->color('gray')
                ->visible(fn (): bool => AiEvaluationResource::canCreate())
                ->requiresConfirmation()
                ->modalHeading('Загрузить source-backed примеры')
                ->modalDescription('Будут добавлены четыре готовых набора синтетических примеров из Appendix 2. Реальные данные клиентов не используются.')
                ->form([
                    Toggle::make('activate_prompt_versions')
                        ->label('Активировать исходные версии для staging')
                        ->helperText('Включайте только для тестового окружения после проверки промптов и выбора модели.')
                        ->default(false),
                ])
                ->action(function (array $data, MaterializeSourceBackedEvaluations $materializer): void {
                    $actor = Auth::user();
                    if (! $actor instanceof User) {
                        return;
                    }

                    try {
                        $summary = $materializer->handle($actor, (bool) ($data['activate_prompt_versions'] ?? false));
                        Notification::make()
                            ->title('Демонстрационные проверки доступны')
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
                            ->title('Не удалось загрузить демонстрационные проверки')
                            ->body($exception instanceof \InvalidArgumentException ? $exception->getMessage() : 'Проверьте настройки и повторите попытку.')
                            ->danger()
                            ->send();
                    }
                }),
        ];
    }
}
