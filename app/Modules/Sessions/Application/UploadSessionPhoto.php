<?php

namespace App\Modules\Sessions\Application;

use App\Models\User;
use App\Modules\Attachments\Application\DeleteMedicalAttachment;
use App\Modules\Attachments\Application\DTOs\AttachmentUploadCommand;
use App\Modules\Attachments\Application\UploadMedicalAttachment;
use App\Modules\Attachments\Domain\Enums\AttachmentType;
use App\Modules\Attachments\Domain\Models\MedicalAttachment;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Sessions\Domain\Enums\MedicalSessionAttachmentGallery;
use App\Modules\Sessions\Domain\Models\MedicalSession;
use App\Modules\Sessions\Domain\Models\MedicalSessionAttachment;
use Illuminate\Http\UploadedFile;

final readonly class UploadSessionPhoto
{
    public function __construct(
        private UploadMedicalAttachment $uploader,
        private LinkSessionAttachment $linker,
        private DeleteMedicalAttachment $deleter,
    ) {}

    public function handle(
        User $actor,
        MedicalSession $session,
        Client $client,
        UploadedFile $file,
        MedicalSessionAttachmentGallery $gallery,
    ): MedicalSessionAttachment {
        $attachment = $this->uploader->handle(
            $actor,
            new AttachmentUploadCommand(
                file: $file,
                clientId: (int) $client->getKey(),
                attachmentType: AttachmentType::PosturePhoto,
            ),
        );

        try {
            return $this->linker->handle(
                $actor,
                $session,
                $client,
                (int) $attachment->getKey(),
                $gallery,
            );
        } catch (\Throwable $exception) {
            $this->deleteUploadedAttachment($actor, $attachment);

            throw $exception;
        }
    }

    private function deleteUploadedAttachment(User $actor, MedicalAttachment $attachment): void
    {
        try {
            $this->deleter->handle($actor, $attachment);
        } catch (\Throwable) {
        }
    }
}
