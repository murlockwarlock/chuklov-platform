<?php

namespace App\Modules\Identity\Application;

use App\Modules\Identity\Domain\Enums\LegalDocumentStatus;
use App\Modules\Identity\Domain\Models\LegalDocument;
use App\Modules\Organizations\Application\OrganizationContext;
use Illuminate\Support\Collection;

class ListPublishedLegalDocuments
{
    public function __construct(private readonly OrganizationContext $context) {}

    /** @return Collection<int, LegalDocument> */
    public function handle(?string $locale = null): Collection
    {
        $organizationId = $this->context->id();
        $requestedLocale = is_string($locale) && $locale !== '' ? $locale : 'en';
        $locales = array_values(array_unique([$requestedLocale, 'en']));

        return LegalDocument::query()
            ->where('status', LegalDocumentStatus::Published)
            ->whereIn('locale', $locales)
            ->where('organization_id', $organizationId)
            ->get()
            ->sortBy(function (LegalDocument $document) use ($requestedLocale): int {
                $localePriority = (int) ($document->locale !== $requestedLocale);

                return $localePriority;
            })
            ->unique('document_type')
            ->values();
    }
}
