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

        $size = fstat($stream)['size'] ?? null;
        abort_unless(is_int($size) && $size > 0, 404);
        $range = $this->range($request->header('Range'), $size);
        $status = $range === null ? 200 : 206;
        $start = $range['start'] ?? 0;
        $end = $range['end'] ?? ($size - 1);
        $length = $end - $start + 1;
        if (fseek($stream, $start) !== 0) {
            fclose($stream);
            abort(404);
        }

        return response()->stream(
            function () use ($stream, $length): void {
                $remaining = $length;
                while ($remaining > 0 && ! feof($stream)) {
                    $chunk = fread($stream, min(8192, $remaining));
                    if ($chunk === false || $chunk === '') {
                        break;
                    }
                    echo $chunk;
                    $remaining -= strlen($chunk);
                }
                fclose($stream);
            },
            $status,
            [
                'Accept-Ranges' => 'bytes',
                'Content-Length' => (string) $length,
                'Content-Type' => $this->contentType($item['type'], $item['source']),
                'Content-Disposition' => $this->contentDisposition($item['name'], $item['source']),
                'Cache-Control' => 'private, no-store',
                'X-Content-Type-Options' => 'nosniff',
                ...($range === null ? [] : ['Content-Range' => "bytes {$start}-{$end}/{$size}"]),
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

    /** @return array{start: int, end: int}|null */
    private function range(?string $header, int $size): ?array
    {
        if ($header === null || trim($header) === '') {
            return null;
        }
        if (preg_match('/\Abytes=(\d*)-(\d*)\z/D', trim($header), $matches) !== 1) {
            abort(416, 'Неверный диапазон медиа.');
        }

        $start = $matches[1] === '' ? null : (int) $matches[1];
        $end = $matches[2] === '' ? null : (int) $matches[2];
        if ($start === null && $end === null) {
            abort(416, 'Неверный диапазон медиа.');
        }
        if ($start === null) {
            $length = min($end ?? 0, $size);
            $start = $size - $length;
            $end = $size - 1;
        } else {
            if ($start >= $size) {
                abort(416, 'Диапазон медиа недоступен.');
            }
            $end = min($end ?? ($size - 1), $size - 1);
        }
        if ($end < $start) {
            abort(416, 'Диапазон медиа недоступен.');
        }

        return ['start' => $start, 'end' => $end];
    }

    private function contentDisposition(?string $name, string $source): string
    {
        $name = trim($name ?: basename($source));
        $name = preg_replace('/[\r\n"]+/', '', $name) ?: 'media';

        return 'inline; filename="media"; filename*=UTF-8\'\''.rawurlencode($name);
    }
}
