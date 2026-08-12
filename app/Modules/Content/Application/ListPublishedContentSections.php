<?php

namespace App\Modules\Content\Application;

use App\Modules\Content\Domain\Models\ContentSection;
use App\Modules\Organizations\Application\OrganizationContext;
use Illuminate\Database\Eloquent\Collection;

class ListPublishedContentSections
{
    public function __construct(private readonly OrganizationContext $context) {}

    /** @return Collection<int, ContentSection> */
    public function handle(string $sectionKey): Collection
    {
        return ContentSection::query()
            ->where('organization_id', $this->context->id())
            ->where('section_key', $sectionKey)
            ->where('is_visible', true)
            ->orderBy('sort_order')
            ->orderBy('locale')
            ->get();
    }
}
