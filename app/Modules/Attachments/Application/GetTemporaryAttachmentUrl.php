<?php

namespace App\Modules\Attachments\Application;

use App\Models\User;
use App\Modules\Attachments\Domain\Models\MedicalAttachment;
use Illuminate\Support\Facades\URL;

final readonly class GetTemporaryAttachmentUrl
{
    public function __construct(
        private AttachmentAuthorization $authorization,
    ) {}

    public function handle(User $actor, MedicalAttachment $attachment, int $ttlMinutes = 15): string
    {
        $this->authorization->authorizeDownload($actor, $attachment);

        return URL::temporarySignedRoute(
            'admin.attachments.download',
            now()->addMinutes($ttlMinutes),
            ['uuid' => $attachment->uuid],
        );
    }
}
