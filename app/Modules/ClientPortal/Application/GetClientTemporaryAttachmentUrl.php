<?php

namespace App\Modules\ClientPortal\Application;

use App\Modules\Attachments\Application\GetTemporaryAttachmentUrl;
use App\Modules\Attachments\Domain\Enums\AttachmentType;
use App\Modules\Attachments\Domain\Models\MedicalAttachment;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\URL;

final readonly class GetClientTemporaryAttachmentUrl
{
    public function __construct(private ClientPortalContext $clientContext) {}

    public function handle(MedicalAttachment $attachment, int $ttlMinutes = 15, string $mode = 'download'): string
    {
        $this->assertAccessible($attachment);

        if ($mode === 'preview' && ! GetTemporaryAttachmentUrl::supportsPreview($attachment->mime_type)) {
            throw new AuthorizationException('This material cannot be previewed.');
        }

        if (! in_array($mode, ['download', 'preview'], true)) {
            throw new AuthorizationException('This material mode is invalid.');
        }

        return URL::temporarySignedRoute(
            'portal.medical-attachments.download',
            now()->addMinutes($ttlMinutes),
            ['uuid' => $attachment->uuid, 'mode' => $mode],
        );
    }

    public function assertAccessible(MedicalAttachment $attachment): void
    {
        $client = $this->clientContext->client();

        if ((int) $attachment->organization_id !== (int) $client->organization_id
            || (int) $attachment->client_id !== (int) $client->getKey()
            || ! in_array($attachment->attachment_type, [AttachmentType::MedicalReport, AttachmentType::PosturePhoto], true)) {
            throw new AuthorizationException('This material is not available in the client portal.');
        }
    }
}
