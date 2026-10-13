<?php

namespace App\Modules\ClientPortal\Application;

use App\Modules\Attachments\Application\DTOs\AttachmentUploadCommand;
use App\Modules\Attachments\Application\UploadMedicalAttachment;
use App\Modules\Attachments\Domain\Exceptions\InvalidAttachmentException;
use App\Modules\Attachments\Domain\Exceptions\UnsupportedDicomException;
use App\Modules\Attachments\Domain\Models\MedicalAttachment;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final readonly class UploadClientMedicalAttachment
{
    public function __construct(private ClientPortalContext $context, private UploadMedicalAttachment $upload) {}

    public function handle(UploadedFile $file, string $uploadKey): MedicalAttachment
    {
        Validator::make(['file' => $file, 'upload_key' => $uploadKey], [
            'file' => ['required', 'file', 'mimetypes:image/jpeg,image/png,application/pdf', 'max:20480'],
            'upload_key' => ['required', 'uuid'],
        ])->validate();
        $client = $this->context->client();
        try {
            return $this->upload->handle($client, new AttachmentUploadCommand($file, (int) $client->getKey(), uploadKey: $uploadKey));
        } catch (InvalidAttachmentException|UnsupportedDicomException $exception) {
            throw ValidationException::withMessages(['file' => $exception->getMessage()]);
        }
    }
}
