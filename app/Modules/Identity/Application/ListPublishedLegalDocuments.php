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
        $normalizedLocale = strtolower((string) $locale);
        $requestedLocale = str_starts_with($normalizedLocale, 'ru') ? 'ru' : 'en';
        $locales = array_values(array_unique([$requestedLocale, 'en', 'ru']));

        return LegalDocument::query()
            ->where('status', LegalDocumentStatus::Published)
            ->whereIn('locale', $locales)
            ->where('organization_id', $organizationId)
            ->get()
            ->sortBy(function (LegalDocument $document) use ($locales): int {
                $localePriority = array_search($document->locale, $locales, true);

                return $localePriority === false ? count($locales) : $localePriority;
            })
            ->unique('document_type')
            ->values();
    }
}
