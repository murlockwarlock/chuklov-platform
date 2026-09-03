<?php

namespace App\Filament\Resources\ContentSections\Pages;

use App\Filament\Resources\ContentSections\ContentSectionResource;
use App\Modules\Channels\Application\BuildTelegramContentSectionMessage;
use App\Modules\Channels\Application\TelegramMessagePreview;
use App\Modules\Content\Domain\Models\ContentSection;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Contracts\View\View;

class ViewContentSection extends ViewRecord
{
    protected static string $resource = ContentSectionResource::class;

    protected static ?string $title = 'Раздел контента';

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make()
                ->label('Редактировать раздел')
                ->icon('heroicon-o-pencil-square')
                ->color('primary'),
            Action::make('previewTelegram')
                ->label('Предпросмотр Telegram')
                ->icon('heroicon-o-eye')
                ->visible(fn (): bool => $this->section()->delivery_mode->supportsTelegram())
                ->modalHeading('Предпросмотр Telegram')
                ->modalSubmitAction(false)
                ->modalCancelActionLabel('Закрыть')
                ->modalContent(function (): View {
                    $message = app(BuildTelegramContentSectionMessage::class)->handle(
                        'preview',
                        $this->section(),
                        $this->section()->locale,
                    );

                    return view('filament.resources.broadcasts.preview', [
                        'preview' => app(TelegramMessagePreview::class)->handle($message),
                        'summary' => null,
                        'reasonLabels' => [],
                    ]);
                }),
        ];
    }

    private function section(): ContentSection
    {
        abort_unless($this->record instanceof ContentSection, 404);

        return $this->record;
    }
}
