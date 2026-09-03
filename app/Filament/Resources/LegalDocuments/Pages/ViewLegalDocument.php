<?php

namespace App\Filament\Resources\LegalDocuments\Pages;

use App\Filament\Resources\LegalDocuments\LegalDocumentResource;
use App\Filament\Support\RichTextEditor;
use App\Modules\Identity\Application\CreateLegalDocumentVersionDraft;
use App\Modules\Identity\Application\PublishLegalDocument;
use App\Modules\Identity\Domain\Enums\LegalDocumentStatus;
use App\Modules\Identity\Domain\Models\LegalDocument;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

final class ViewLegalDocument extends ViewRecord
{
    protected static string $resource = LegalDocumentResource::class;

    protected static ?string $title = 'Юридический документ';

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make()
                ->label('Редактировать draft')
                ->visible(fn (): bool => $this->document()->status === LegalDocumentStatus::Draft),
            Action::make('publish')
                ->label('Опубликовать')
                ->color('success')
                ->requiresConfirmation()
                ->visible(fn (): bool => $this->document()->status === LegalDocumentStatus::Draft)
                ->action(function (): void {
                    app(PublishLegalDocument::class)->handle($this->document());
                    Notification::make()->title('Документ опубликован')->success()->send();
                    $this->refreshFormData(['status', 'published_at', 'archived_at']);
                }),
            Action::make('newVersion')
                ->label('Создать новую draft-версию')
                ->color('gray')
                ->visible(fn (): bool => $this->document()->status === LegalDocumentStatus::Published)
                ->fillForm(fn (): array => [
                    'version' => $this->document()->version.'-next',
                    'content' => $this->document()->content,
                ])
                ->schema([
                    TextInput::make('version')->label('Новая версия')->required()->maxLength(64),
                    RichTextEditor::make('content')->label('Текст новой версии')->required(),
                ])
                ->action(function (array $data): void {
                    $draft = app(CreateLegalDocumentVersionDraft::class)->handle(
                        $this->document(),
                        (string) ($data['version'] ?? ''),
                        (string) ($data['content'] ?? ''),
                    );
                    Notification::make()->title('Новая draft-версия создана')->body('Версия: '.$draft->version)->success()->send();
                }),
        ];
    }

    private function document(): LegalDocument
    {
        abort_unless($this->record instanceof LegalDocument, 404);

        return $this->record;
    }
}
