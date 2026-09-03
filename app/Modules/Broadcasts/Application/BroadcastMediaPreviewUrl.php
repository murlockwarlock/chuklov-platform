<?php

namespace App\Modules\Broadcasts\Application;

use App\Modules\Broadcasts\Domain\Models\BroadcastCampaign;
use App\Modules\Organizations\Application\OrganizationContext;
use Illuminate\Support\Facades\URL;
use LogicException;

final readonly class BroadcastMediaPreviewUrl
{
    public function __construct(private BroadcastCampaignMedia $media) {}

    public function handle(BroadcastCampaign $campaign, int $mediaIndex): ?string
    {
        if ($mediaIndex < 0) {
            return null;
        }

        try {
            if (app(OrganizationContext::class)->id() !== (int) $campaign->organization_id) {
                return null;
            }
        } catch (LogicException) {
            return null;
        }

        $items = $this->media->items($campaign->media);
        $item = $items[$mediaIndex] ?? null;
        if ($item === null || ! $this->media->isManagedPath((int) $campaign->organization_id, $item['source'])) {
            return null;
        }

        return URL::temporarySignedRoute(
            'admin.broadcasts.media',
            now()->addMinutes(30),
            [
                'campaignId' => $campaign->getKey(),
                'mediaIndex' => $mediaIndex,
            ],
        );
    }
}
