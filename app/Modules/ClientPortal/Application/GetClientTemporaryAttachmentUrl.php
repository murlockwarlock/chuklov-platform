<?php

namespace App\Modules\ClientPortal\Application;

use App\Modules\Attachments\Application\GetTemporaryAttachmentUrl;
use App\Modules\Attachments\Domain\Enums\AttachmentType;
use App\Modules\Attachments\Domain\Models\MedicalAttachment;
use App\Modules\Identity\Application\PatientProfileAccess;
use App\Modules\Identity\Domain\Models\Client;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\URL;

final readonly class GetClientTemporaryAttachmentUrl
{
    public function __construct(private ClientPortalContext $clientContext, private PatientProfileAccess $patients) {}

    public function handle(MedicalAttachment $attachment, int $ttlMinutes = 15, string $mode = 'download'): string
    {
        $this->assertAccessible($attachment);

        if ($mode === 'preview' && ! GetTemporaryAttachmentUrl::supportsPreview($attachment->mime_type)) {
            throw new AuthorizationException('This material cannot be previewed.');
        }

        if (! in_array($mode, ['download', 'preview'], true)) {
            throw new AuthorizationException('This material mode is invalid.');
        }

        return $this->temporaryUrl($attachment, $ttlMinutes, $mode);
    }

    /**
     * @param  Collection<int, MedicalAttachment>  $materials
     * @return array<int, array{downloadUrl: string, previewUrl: ?string}>
     */
    public function forClientMaterials(Client $subject, Collection $materials): array
    {
        $this->patients->authorizeClinicalClient($this->clientContext->client(), $subject);
        $result = [];
        foreach ($materials as $attachment) {
            if ((int) $attachment->organization_id !== (int) $subject->organization_id
                || (int) $attachment->client_id !== (int) $subject->getKey()
                || ! in_array($attachment->attachment_type, [AttachmentType::MedicalReport, AttachmentType::PosturePhoto], true)) {
                throw new AuthorizationException;
            }
            $result[(int) $attachment->getKey()] = [
                'downloadUrl' => $this->temporaryUrl($attachment, 15, 'download'),
                'previewUrl' => GetTemporaryAttachmentUrl::supportsPreview($attachment->mime_type)
                    ? $this->temporaryUrl($attachment, 15, 'preview') : null,
            ];
        }

        return $result;
    }

    private function temporaryUrl(MedicalAttachment $attachment, int $ttlMinutes, string $mode): string
    {
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
            || ! in_array($attachment->attachment_type, [AttachmentType::MedicalReport, AttachmentType::PosturePhoto], true)) {
            throw new AuthorizationException('This material is not available in the client portal.');
        }
        $subject = Client::query()->where('organization_id', $client->organization_id)->whereKey($attachment->client_id)->firstOrFail();
        $this->patients->authorizeClinicalClient($client, $subject);
    }
}
