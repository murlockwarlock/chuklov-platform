<?php

namespace App\Modules\ClientPortal\Application;

use App\Modules\Attachments\Application\DTOs\AttachmentDownloadResult;
use App\Modules\Attachments\Domain\Contracts\AttachmentStorageInterface;
use App\Modules\Attachments\Domain\Models\MedicalAttachment;
use App\Modules\ClientPortal\Domain\Exceptions\MedicalAttachmentDownloadFailed;
use App\Modules\Security\Application\RecordAuditEvent;
use Illuminate\Auth\Access\AuthorizationException;
use Throwable;

final readonly class DownloadClientMedicalAttachment
{
    public function __construct(
        private ClientPortalContext $context,
        private GetClientTemporaryAttachmentUrl $urls,
        private AttachmentStorageInterface $storage,
        private RecordAuditEvent $audit,
    ) {}

    public function handle(MedicalAttachment $attachment): AttachmentDownloadResult
    {
        $client = $this->context->client();
        $stream = null;
        $stage = 'authorization';

        try {
            $this->urls->assertAccessible($attachment);
            $stage = 'private_storage_read';
            $stream = $this->storage->readStream($attachment->storage_path);
            $stage = 'audit_write';
            $this->audit->handle(
                organization: $attachment->organization()->firstOrFail(),
                actor: null,
                action: 'attachment.downloaded',
                targetType: MedicalAttachment::class,
                targetId: (string) $attachment->getKey(),
                metadata: [
                    'source' => 'portal',
                    'actor_type' => 'client',
                    'actor_client_id' => $client->getKey(),
                ],
            );
        } catch (AuthorizationException $exception) {
            if (is_resource($stream)) {
                fclose($stream);
            }

            throw $exception;
        } catch (Throwable $exception) {
            if (is_resource($stream)) {
                fclose($stream);
            }

            throw new MedicalAttachmentDownloadFailed($stage, $exception);
        }

        return new AttachmentDownloadResult(
            stream: $stream,
            filename: $attachment->original_filename,
            mimeType: $attachment->mime_type,
            sizeBytes: $attachment->size_bytes,
        );
    }
}
