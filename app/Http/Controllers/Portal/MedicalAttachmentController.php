<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Modules\Attachments\Application\GetTemporaryAttachmentUrl;
use App\Modules\Attachments\Domain\Models\MedicalAttachment;
use App\Modules\ClientPortal\Application\ClientPortalContext;
use App\Modules\ClientPortal\Application\DownloadClientMedicalAttachment;
use App\Modules\ClientPortal\Application\GetClientTemporaryAttachmentUrl;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class MedicalAttachmentController extends Controller
{
    public function __invoke(
        Request $request,
        string $uuid,
        ClientPortalContext $context,
        GetClientTemporaryAttachmentUrl $urls,
        DownloadClientMedicalAttachment $download,
    ): StreamedResponse {
        abort_unless($request->hasValidSignature(), 403);
        $mode = $request->query('mode');
        abort_unless(is_string($mode) && in_array($mode, ['download', 'preview'], true), 403);
        $attachment = MedicalAttachment::query()
            ->where('organization_id', $context->client()->organization_id)
            ->where('client_id', $context->client()->getKey())
            ->where('uuid', $uuid)
            ->firstOrFail();
        $urls->assertAccessible($attachment);
        if ($mode === 'preview') {
            abort_unless(GetTemporaryAttachmentUrl::supportsPreview($attachment->mime_type), 404);
        }

        $result = $download->handle($attachment);
        $stream = static function () use ($result): void {
            fpassthru($result->stream);
            if (is_resource($result->stream)) {
                fclose($result->stream);
            }
        };

        return response()->stream($stream, 200, [
            'Content-Type' => $result->mimeType,
            'Content-Length' => (string) $result->sizeBytes,
            'Content-Disposition' => HeaderUtils::makeDisposition(
                $mode === 'preview' ? HeaderUtils::DISPOSITION_INLINE : HeaderUtils::DISPOSITION_ATTACHMENT,
                $result->filename,
                'download',
            ),
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
