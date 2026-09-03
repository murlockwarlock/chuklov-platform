<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Modules\Broadcasts\Application\BroadcastCampaignMedia;
use App\Modules\Broadcasts\Domain\Models\BroadcastCampaign;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class AdminBroadcastMediaController extends Controller
{
    public function __invoke(
        Request $request,
        int $campaignId,
        int $mediaIndex,
        BroadcastCampaignMedia $media,
    ): StreamedResponse {
        abort_unless($request->hasValidSignature(), 403, 'Ссылка на медиа недействительна или срок её действия истёк.');

        $actor = $request->user();
        abort_unless($actor instanceof User, 403);
        $organization = app(OrganizationContext::class)->organization();
        app(OrganizationAuthorizer::class)->authorize($actor, $organization, OrganizationPermission::ViewScenarios);
        $campaign = BroadcastCampaign::query()
            ->where('organization_id', $organization->getKey())
            ->whereKey($campaignId)
            ->firstOrFail();
        $item = $media->items($campaign->media)[$mediaIndex] ?? null;
        abort_unless(is_array($item) && $media->isManagedPath($organization->getKey(), $item['source']), 404);

        $stream = $media->readStream($organization->getKey(), $item['source']);
        abort_unless(is_resource($stream), 404);

        return response()->stream(
            function () use ($stream): void {
                fpassthru($stream);
                fclose($stream);
            },
            200,
            [
                'Content-Type' => $this->contentType($item['type'], $item['source']),
                'Content-Disposition' => $this->contentDisposition($item['name'], $item['source']),
                'X-Content-Type-Options' => 'nosniff',
            ],
        );
    }

    private function contentType(string $type, string $source): string
    {
        if ($type === 'video') {
            return 'video/mp4';
        }

        if ($type === 'photo') {
            return match (strtolower(pathinfo($source, PATHINFO_EXTENSION))) {
                'png' => 'image/png',
                'webp' => 'image/webp',
                default => 'image/jpeg',
            };
        }

        return 'application/octet-stream';
    }

    private function contentDisposition(?string $name, string $source): string
    {
        $name = trim($name ?: basename($source));
        $name = preg_replace('/[\r\n"]+/', '', $name) ?: 'media';

        return 'inline; filename="media"; filename*=UTF-8\'\''.rawurlencode($name);
    }
}
