<?php

namespace App\Modules\Identity\Application;

use App\Modules\Identity\Domain\Enums\LegalDocumentManagementMode;
use App\Modules\Identity\Domain\Enums\LegalDocumentStatus;
use App\Modules\Identity\Domain\Models\LegalDocument;
use App\Modules\Security\Application\RecordAuditEvent;
use Illuminate\Auth\Access\AuthorizationException;
use InvalidArgumentException;

class UpdateLegalDocumentDraft
{
    public function __construct(private readonly RecordAuditEvent $audit) {}

    public function handle(LegalDocument $document, string $content): LegalDocument
    {
        if ($document->status !== LegalDocumentStatus::Draft
            || $document->management_mode !== LegalDocumentManagementMode::PlatformManaged) {
            throw new AuthorizationException('Only platform-managed drafts can be changed in Phase 1.');
        }

        $content = trim($content);

        if ($content === '') {
            throw new InvalidArgumentException('The legal document content is required.');
        }

        $document->forceFill(['content' => $content]);
        $document->save();

        $this->audit->handle(
            organization: $document->organization,
            actor: null,
            action: 'legal.document.draft.updated',
            targetType: LegalDocument::class,
            targetId: (string) $document->getKey(),
            metadata: [
                'document_type' => $document->document_type,
                'version' => $document->version,
                'locale' => $document->locale,
            ],
        );

        return $document->refresh();
    }
}
