<?php

namespace App\Modules\ClientCompanion\Application\Services;

use App\Modules\Conversations\Domain\Models\ConversationMessage;
use App\Modules\MedicalProfiles\Domain\Contracts\MedicalEncryptorInterface;

final class CompanionMessageBodyReader
{
    public function __construct(private readonly MedicalEncryptorInterface $encryptor) {}

    public function read(int $organizationId, ConversationMessage $message): string
    {
        if ($message->encrypted_body !== null && $message->encryption_key_version !== null) {
            return (string) $this->encryptor->decryptField(
                $organizationId,
                $message->encrypted_body,
                (int) $message->encryption_key_version,
            );
        }

        return (string) ($message->body ?? '');
    }
}
