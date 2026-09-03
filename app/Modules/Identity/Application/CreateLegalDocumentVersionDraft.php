<?php

namespace App\Modules\Identity\Application;

use App\Modules\Identity\Domain\Enums\ConsentSubject;
use App\Modules\Identity\Domain\Enums\LegalDocumentManagementMode;
use App\Modules\Identity\Domain\Enums\LegalDocumentStatus;
use App\Modules\Identity\Domain\Models\LegalDocument;
use Illuminate\Auth\Access\AuthorizationException;
use InvalidArgumentException;

final class CreateLegalDocumentVersionDraft
{
    public function __construct(private readonly CreatePlatformLegalDocumentDraft $createDraft) {}

    public function handle(LegalDocument $source, string $version, string $content): LegalDocument
    {
        if ($source->status !== LegalDocumentStatus::Published
            || $source->management_mode !== LegalDocumentManagementMode::PlatformManaged) {
            throw new AuthorizationException('A new legal document version can only be based on a published version.');
        }

        $subject = ConsentSubject::tryFrom($source->document_type);

        if (! $subject instanceof ConsentSubject) {
            throw new InvalidArgumentException('The legal document purpose is not configured.');
        }

        return $this->createDraft->handle(
            organization: $source->organization,
            documentType: $source->document_type,
            purpose: $source->purpose,
            locale: $source->locale,
            version: $version,
            content: $content,
            isRequired: $subject->isRequired(),
        );
    }
}
