<?php

namespace App\Modules\Attachments\Application;

use App\Models\User;
use App\Modules\Attachments\Domain\Contracts\AttachmentStorageInterface;
use App\Modules\Attachments\Domain\Models\MedicalAttachment;
use App\Modules\Security\Application\RecordAuditEvent;
use Illuminate\Support\Facades\DB;

final readonly class DeleteMedicalAttachment
{
    public function __construct(
        private AttachmentAuthorization $authorization,
        private AttachmentStorageInterface $storage,
        private RecordAuditEvent $audit,
    ) {}

    public function handle(User $actor, MedicalAttachment $attachment): bool
    {
        $organization = $this->authorization->authorizeDelete($actor, $attachment);

        /** @var array{deleted: bool, storage_path: string|null} $result */
        $result = DB::transaction(function () use ($actor, $organization, $attachment): array {
            $locked = MedicalAttachment::query()
                ->where('organization_id', $organization->getKey())
                ->whereKey($attachment->getKey())
                ->lockForUpdate()
                ->first();

            if (! $locked instanceof MedicalAttachment) {
                return ['deleted' => false, 'storage_path' => null];
            }

            $storagePath = filled($locked->storage_path) ? (string) $locked->storage_path : null;
            $locked->sessionLinks()->delete();
            $deleted = $locked->delete();

            if ($deleted) {
                $this->audit->handle(
                    organization: $organization,
                    actor: $actor,
                    action: 'attachment.deleted',
                    targetType: MedicalAttachment::class,
                    targetId: (string) $locked->getKey(),
                    metadata: [
                        'attachment_type' => $locked->attachment_type->value,
                    ],
                );
            }

            return ['deleted' => $deleted, 'storage_path' => $storagePath];
        });

        if ($result['deleted'] && $result['storage_path'] !== null) {
            $this->storage->delete($result['storage_path']);
        }

        return $result['deleted'];
    }
}
