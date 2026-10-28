<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Modules\Scenarios\Application\ReadNotificationTemplateMedia;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;

final class AdminNotificationTemplateMediaController extends Controller
{
    public function __invoke(Request $request, int $versionId, int $mediaIndex, ReadNotificationTemplateMedia $media): BinaryFileResponse
    {
        abort_unless($request->hasValidSignature() && $request->user() instanceof User, 403);
        $file = $media->handle($request->user(), $versionId, $mediaIndex);
        $response = response()->file($file['path'], ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
        if (! in_array($file['type'], ['photo', 'video'], true)) {
            $response->headers->set('Content-Type', 'application/octet-stream');
        }

        return $response->setContentDisposition($file['type'] === 'document' ? ResponseHeaderBag::DISPOSITION_ATTACHMENT : ResponseHeaderBag::DISPOSITION_INLINE, $file['name'], 'media');
    }
}
