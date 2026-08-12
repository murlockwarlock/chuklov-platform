<?php

namespace App\Modules\Identity\Application;

use App\Modules\Identity\Domain\Enums\LegalDocumentManagementMode;
use App\Modules\Identity\Domain\Enums\LegalDocumentStatus;
use App\Modules\Identity\Domain\Models\LegalDocument;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Security\Application\RecordAuditEvent;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use LogicException;

class PublishLegalDocument
{
    public function __construct(private readonly RecordAuditEvent $audit) {}

    public function handle(LegalDocument $document): LegalDocument
    {
        if ($document->status !== LegalDocumentStatus::Draft
            || $document->management_mode !== LegalDocumentManagementMode::PlatformManaged) {
            throw new AuthorizationException('Only platform-managed drafts can be published in Phase 1.');
        }

        try {
            return DB::transaction(function () use ($document): LegalDocument {
                $organization = Organization::query()
                    ->whereKey($document->organization_id)
                    ->lockForUpdate()
                    ->firstOrFail();

                $document = LegalDocument::query()
                    ->whereKey($document->getKey())
                    ->where('organization_id', $organization->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($document->status !== LegalDocumentStatus::Draft
                    || $document->management_mode !== LegalDocumentManagementMode::PlatformManaged) {
                    throw new AuthorizationException('Only platform-managed drafts can be published in Phase 1.');
                }

                $published = LegalDocument::query()
                    ->where('document_type', $document->document_type)
                    ->where('locale', $document->locale)
                    ->where('status', LegalDocumentStatus::Published)
                    ->where('organization_id', $organization->getKey())
                    ->lockForUpdate()
                    ->get();

                foreach ($published as $previous) {
                    $previous->forceFill([
                        'status' => LegalDocumentStatus::Archived,
                        'archived_at' => now(),
                    ]);
                    $previous->save();
                }

                $document->forceFill([
                    'status' => LegalDocumentStatus::Published,
                    'effective_at' => now(),
                    'published_at' => now(),
                ]);
                $document->save();

                $document->setRelation('organization', $organization);

                $this->audit->handle(
                    organization: $organization,
                    actor: null,
                    action: 'legal.document.published',
                    targetType: LegalDocument::class,
                    targetId: (string) $document->getKey(),
                    metadata: [
                        'document_type' => $document->document_type,
                        'version' => $document->version,
                        'locale' => $document->locale,
                        'management_mode' => $document->management_mode->value,
                    ],
                );

                return $document->refresh();
            });
        } catch (UniqueConstraintViolationException) {
            throw new LogicException('A legal document is already published for this organization, type, and locale.');
        }
    }
}
