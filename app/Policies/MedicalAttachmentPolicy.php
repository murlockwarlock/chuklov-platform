<?php

namespace App\Policies;

use App\Models\User;
use App\Modules\Attachments\Application\AttachmentAuthorization;
use App\Modules\Attachments\Domain\Models\MedicalAttachment;

final class MedicalAttachmentPolicy
{
    public function view(User $user, MedicalAttachment $attachment): bool
    {
        return app(AttachmentAuthorization::class)->allowsDownload($user, $attachment);
    }

    public function create(User $user): bool
    {
        return app(AttachmentAuthorization::class)->allowsUpload($user);
    }

    public function delete(User $user, MedicalAttachment $attachment): bool
    {
        return app(AttachmentAuthorization::class)->allowsUpload($user);
    }
}
