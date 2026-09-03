<?php

namespace App\Filament\Resources\LegalDocuments\Pages;

use App\Filament\Resources\LegalDocuments\LegalDocumentResource;
use App\Models\User;
use App\Modules\Identity\Application\UpdateLegalDocumentDraft;
use App\Modules\Identity\Domain\Models\LegalDocument;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

final class EditLegalDocument extends EditRecord
{
    protected static string $resource = LegalDocumentResource::class;

    protected static ?string $title = 'Редактировать draft документа';

    protected function mutateFormDataBeforeFill(array $data): array
    {
        abort_unless($this->record instanceof LegalDocument && LegalDocumentResource::canEdit($this->record), 404);

        return $data;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        abort_unless($record instanceof LegalDocument && LegalDocumentResource::canEdit($record), 403);
        abort_unless(auth()->user() instanceof User, 403);

        return app(UpdateLegalDocumentDraft::class)->handle($record, (string) ($data['content'] ?? ''));
    }
}
