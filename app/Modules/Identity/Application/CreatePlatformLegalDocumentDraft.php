<?php

namespace App\Modules\Identity\Application;

use App\Modules\Identity\Domain\Enums\LegalDocumentManagementMode;
use App\Modules\Identity\Domain\Enums\LegalDocumentStatus;
use App\Modules\Identity\Domain\Models\LegalDocument;
use App\Modules\Organizations\Domain\Models\Organization;
use Illuminate\Auth\Access\AuthorizationException;
use InvalidArgumentException;

class CreatePlatformLegalDocumentDraft
{
    public function handle(
        Organization $organization,
        string $documentType,
        string $purpose,
        string $locale,
        string $version,
        string $content,
        bool $isRequired,
        LegalDocumentManagementMode $managementMode = LegalDocumentManagementMode::PlatformManaged,
    ): LegalDocument {
        if ($managementMode !== LegalDocumentManagementMode::PlatformManaged) {
            throw new AuthorizationException('The organization cannot enable organization-managed legal content.');
        }

        $documentType = trim($documentType);
        $purpose = trim($purpose);
        $locale = trim($locale);
        $version = trim($version);
        $content = trim($content);

        if ($documentType === '' || mb_strlen($documentType) > 64 || preg_match('/^[a-z0-9._-]+$/', $documentType) !== 1
            || $purpose === '' || mb_strlen($purpose) > 120
            || preg_match('/^[a-z]{2}(?:-[A-Z]{2})?$/', $locale) !== 1
            || $version === '' || mb_strlen($version) > 64
            || $content === '') {
            throw new InvalidArgumentException('The legal document draft is invalid.');
        }

        $document = new LegalDocument;
        $document->forceFill([
            'organization_id' => $organization->getKey(),
            'document_type' => $documentType,
            'purpose' => $purpose,
            'locale' => $locale,
            'management_mode' => LegalDocumentManagementMode::PlatformManaged,
            'status' => LegalDocumentStatus::Draft,
            'version' => $version,
            'content' => $content,
            'is_required' => $isRequired,
        ]);
        $document->save();

        return $document->refresh();
    }
}
