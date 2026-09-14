<?php

namespace App\Modules\Attachments\Application;

use App\Models\User;
use App\Modules\Attachments\Domain\Models\MedicalAttachment;
use Illuminate\Support\Facades\URL;

final readonly class GetTemporaryAttachmentUrl
{
    private const PREVIEWABLE_MIME_TYPES = [
        'application/pdf',
        'image/jpeg',
        'image/png',
        'image/webp',
        'text/plain',
    ];

    public function __construct(
        private AttachmentAuthorization $authorization,
    ) {}

    public function handle(User $actor, MedicalAttachment $attachment, int $ttlMinutes = 15): string
    {
        $this->authorization->authorizeDownload($actor, $attachment);

        return $this->signedUrl($attachment, $ttlMinutes, 'download');
    }

    public function handlePreview(User $actor, MedicalAttachment $attachment, int $ttlMinutes = 15): ?string
    {
        $this->authorization->authorizeDownload($actor, $attachment);

        if (! self::supportsPreview($attachment->mime_type)) {
            return null;
        }

        return $this->signedUrl($attachment, $ttlMinutes, 'preview');
    }

    public static function supportsPreview(string $mimeType): bool
    {
        return in_array(strtolower($mimeType), self::PREVIEWABLE_MIME_TYPES, true);
    }

    private function signedUrl(MedicalAttachment $attachment, int $ttlMinutes, string $mode): string
    {
        return URL::temporarySignedRoute(
            'admin.attachments.download',
            now()->addMinutes($ttlMinutes),
            ['uuid' => $attachment->uuid, 'mode' => $mode],
        );
    }
}
