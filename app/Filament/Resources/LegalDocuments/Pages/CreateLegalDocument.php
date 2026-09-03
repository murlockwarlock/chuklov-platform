<?php

namespace App\Filament\Resources\LegalDocuments\Pages;

use App\Filament\Resources\LegalDocuments\LegalDocumentResource;
use App\Models\User;
use App\Modules\Identity\Application\CreatePlatformLegalDocumentDraft;
use App\Modules\Identity\Domain\Enums\ConsentSubject;
use App\Modules\Organizations\Application\OrganizationContext;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

final class CreateLegalDocument extends CreateRecord
{
    protected static string $resource = LegalDocumentResource::class;

    protected static ?string $title = 'Создать draft документа';

    protected function handleRecordCreation(array $data): Model
    {
        $actor = auth()->user();
        abort_unless($actor instanceof User && LegalDocumentResource::canCreate(), 403);
        $subject = ConsentSubject::tryFrom((string) ($data['document_type'] ?? ''));

        if (! $subject instanceof ConsentSubject) {
            throw ValidationException::withMessages(['document_type' => 'Выберите тип документа.']);
        }

        return app(CreatePlatformLegalDocumentDraft::class)->handle(
            organization: app(OrganizationContext::class)->organization(),
            documentType: $subject->value,
            purpose: (string) ($data['purpose'] ?? ''),
            locale: (string) ($data['locale'] ?? ''),
            version: (string) ($data['version'] ?? ''),
            content: (string) ($data['content'] ?? ''),
            isRequired: $subject->isRequired(),
        );
    }
}
