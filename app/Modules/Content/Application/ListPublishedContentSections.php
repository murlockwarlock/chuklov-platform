<?php

namespace App\Modules\Content\Application;

use App\Modules\Content\Domain\Enums\ContentDeliveryMode;
use App\Modules\Content\Domain\Models\ContentSection;
use App\Modules\Organizations\Application\OrganizationContext;
use Illuminate\Database\Eloquent\Collection;

class ListPublishedContentSections
{
    public function __construct(private readonly OrganizationContext $context) {}

    /** @return Collection<int, ContentSection> */
    public function handle(string $sectionKey, ?ContentDeliveryMode $channel = null): Collection
    {
        $query = ContentSection::query()
            ->where('organization_id', $this->context->id())
            ->where('section_key', $sectionKey)
            ->where('is_visible', true);

        if ($channel === ContentDeliveryMode::Telegram) {
            $query->whereIn('delivery_mode', [ContentDeliveryMode::Telegram->value, ContentDeliveryMode::Both->value]);
        }

        if ($channel === ContentDeliveryMode::MiniApp) {
            $query->whereIn('delivery_mode', [ContentDeliveryMode::MiniApp->value, ContentDeliveryMode::Both->value]);
        }

        return $query
            ->orderBy('sort_order')
            ->orderBy('locale')
            ->get();
    }
}
