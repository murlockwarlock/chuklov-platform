<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Modules\Attachments\Application\AttachmentAuthorization;
use App\Modules\Attachments\Application\DownloadMedicalAttachment;
use App\Modules\Attachments\Application\GetTemporaryAttachmentUrl;
use App\Modules\Attachments\Domain\Models\MedicalAttachment;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class AdminMedicalAttachmentController extends Controller
{
    public function __invoke(Request $request, string $uuid, DownloadMedicalAttachment $downloadAction): StreamedResponse
    {
        abort_unless($request->hasValidSignature(), 403, 'Ссылка для скачивания недействительна или срок её действия истёк.');

        $mode = $request->query('mode');
        abort_unless(is_string($mode) && in_array($mode, ['download', 'preview'], true), 403);

        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        $organization = app(AttachmentAuthorization::class)->organization();
        $attachment = MedicalAttachment::query()
            ->where('organization_id', $organization->getKey())
            ->where('uuid', $uuid)
            ->firstOrFail();

        if ($mode === 'preview') {
            abort_unless(GetTemporaryAttachmentUrl::supportsPreview($attachment->mime_type), 404);
        }

        $result = $downloadAction->handle($actor, $attachment);
        $stream = static function () use ($result): void {
            fpassthru($result->stream);
            if (is_resource($result->stream)) {
                fclose($result->stream);
            }
        };
        $headers = [
            'Content-Type' => $result->mimeType,
            'Content-Length' => (string) $result->sizeBytes,
            'Content-Disposition' => HeaderUtils::makeDisposition(
                $mode === 'preview' ? HeaderUtils::DISPOSITION_INLINE : HeaderUtils::DISPOSITION_ATTACHMENT,
                $result->filename,
                self::asciiFilename($result->filename),
            ),
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ];

        return response()->stream($stream, 200, $headers);
    }

    private static function asciiFilename(string $filename): string
    {
        $fallback = preg_replace('/[^A-Za-z0-9._-]+/', '_', basename($filename));
        $fallback = is_string($fallback) ? trim($fallback, '._') : '';

        return $fallback !== '' ? $fallback : 'download';
    }
}
