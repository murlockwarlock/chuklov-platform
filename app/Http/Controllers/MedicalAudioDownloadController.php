<?php

namespace App\Http\Controllers;

use App\Modules\Attachments\Application\AttachmentAuthorization;
use App\Modules\Attachments\Domain\Contracts\AttachmentStorageInterface;
use App\Modules\Attachments\Domain\Enums\AttachmentType;
use App\Modules\Attachments\Domain\Models\MedicalAttachment;
use App\Modules\Security\Application\RecordAuditEvent;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class MedicalAudioDownloadController extends Controller
{
    public function __invoke(
        Request $request,
        string $uuid,
        AttachmentAuthorization $authorization,
        AttachmentStorageInterface $storage,
        RecordAuditEvent $audit,
    ): StreamedResponse {
        abort_unless($request->hasValidSignature(), 403, 'Ссылка для скачивания недействительна или срок её действия истёк.');

        $organization = $authorization->organization();
        $attachment = MedicalAttachment::query()
            ->where('organization_id', $organization->getKey())
            ->where('uuid', $uuid)
            ->where('attachment_type', AttachmentType::AudioRecording)
            ->firstOrFail();
        $stream = $storage->readStream($attachment->storage_path);

        $audit->handle(
            organization: $organization,
            actor: null,
            action: 'attachment.downloaded',
            targetType: MedicalAttachment::class,
            targetId: (string) $attachment->getKey(),
            metadata: [
                'source' => 'telegram_fallback',
                'actor_type' => 'client',
            ],
        );

        return response()->stream(
            static function () use ($stream): void {
                fpassthru($stream);
                if (is_resource($stream)) {
                    fclose($stream);
                }
            },
            200,
            [
                'Content-Type' => $attachment->mime_type,
                'Content-Length' => (string) $attachment->size_bytes,
                'Content-Disposition' => HeaderUtils::makeDisposition(
                    HeaderUtils::DISPOSITION_ATTACHMENT,
                    $attachment->original_filename,
                    'audio-download',
                ),
                'Cache-Control' => 'private, no-store',
                'X-Content-Type-Options' => 'nosniff',
            ],
        );
    }
}
