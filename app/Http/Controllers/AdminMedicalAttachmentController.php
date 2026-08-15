<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Modules\Attachments\Application\AttachmentAuthorization;
use App\Modules\Attachments\Application\DownloadMedicalAttachment;
use App\Modules\Attachments\Domain\Models\MedicalAttachment;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class AdminMedicalAttachmentController extends Controller
{
    public function __invoke(Request $request, string $uuid, DownloadMedicalAttachment $downloadAction): StreamedResponse
    {
        abort_unless($request->hasValidSignature(), 403, 'Ссылка для скачивания недействительна или срок её действия истёк.');

        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        $organization = app(AttachmentAuthorization::class)->organization();
        $attachment = MedicalAttachment::query()
            ->where('organization_id', $organization->getKey())
            ->where('uuid', $uuid)
            ->firstOrFail();

        $result = $downloadAction->handle($actor, $attachment);

        return response()->streamDownload(
            function () use ($result): void {
                fpassthru($result->stream);
                if (is_resource($result->stream)) {
                    fclose($result->stream);
                }
            },
            $result->filename,
            [
                'Content-Type' => $result->mimeType,
                'Content-Length' => (string) $result->sizeBytes,
                'X-Content-Type-Options' => 'nosniff',
            ],
        );
    }
}
