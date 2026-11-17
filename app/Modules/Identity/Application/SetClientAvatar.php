<?php

namespace App\Modules\Identity\Application;

use App\Models\User;
use App\Modules\Attachments\Application\DTOs\AttachmentUploadCommand;
use App\Modules\Attachments\Application\UploadMedicalAttachment;
use App\Modules\Attachments\Domain\Enums\AttachmentType;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Identity\Domain\Models\ClientAvatarProfile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\Http\UploadedFile;

final readonly class SetClientAvatar
{
    public function __construct(
        private UploadMedicalAttachment $uploader,
        private ClientProfileAuthorization $authorization,
    ) {}

    public function handle(
        User|Client $actor,
        Client $client,
        UploadedFile $file,
        ?string $uploadKey = null,
        ?string $source = null,
    ): ClientAvatarProfile {
        $this->authorization->authorizeManageAvatar($actor, $client);
        $source ??= $actor instanceof Client ? 'client_upload' : 'specialist_upload';
        if (! in_array($source, ['telegram', 'client_upload', 'specialist_upload'], true)) {
            throw ValidationException::withMessages(['source' => 'Источник аватара недоступен.']);
        }
        $uploadKey ??= (string) Str::uuid();
        if (! Str::isUuid($uploadKey)) {
            throw ValidationException::withMessages(['upload_key' => 'Некорректный ключ загрузки.']);
        }

        $attachment = $this->uploader->handle(
            $actor,
            new AttachmentUploadCommand(
                file: $file,
                clientId: (int) $client->getKey(),
                attachmentType: AttachmentType::ClientAvatar,
                uploadKey: $uploadKey,
            ),
        );

        return DB::transaction(function () use ($client, $attachment, $source): ClientAvatarProfile {
            $profile = ClientAvatarProfile::query()
                ->where('organization_id', $client->organization_id)
                ->where('client_id', $client->getKey())
                ->lockForUpdate()
                ->first() ?? new ClientAvatarProfile;
            $profile->forceFill([
                'organization_id' => $client->organization_id,
                'client_id' => $client->getKey(),
                'medical_attachment_id' => $attachment->getKey(),
                'source' => $source,
            ])->save();

            return $profile->refresh();
        });
    }
}
