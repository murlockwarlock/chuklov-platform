<?php

namespace App\Filament\Resources\ContentSections\Pages;

use App\Filament\Resources\ContentSections\ContentSectionResource;
use App\Filament\Support\LocalizedViewRecord;
use App\Modules\Channels\Application\BuildTelegramContentSectionMessage;
use App\Modules\Channels\Application\TelegramMessagePreview;
use App\Modules\Content\Domain\Models\ContentSection;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Illuminate\Contracts\View\View;

class ViewContentSection extends LocalizedViewRecord
{
    protected static string $resource = ContentSectionResource::class;

    protected static ?string $title = 'Раздел контента';

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make()
                ->label(__('Редактировать раздел'))
                ->icon('heroicon-o-pencil-square')
                ->color('primary'),
            Action::make('previewTelegram')
                ->label(__('Предпросмотр Telegram'))
                ->icon('heroicon-o-eye')
                ->visible(fn (): bool => $this->section()->delivery_mode->supportsTelegram())
                ->modalHeading(__('Предпросмотр Telegram'))
                ->modalSubmitAction(false)
                ->modalCancelActionLabel(__('Закрыть'))
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
