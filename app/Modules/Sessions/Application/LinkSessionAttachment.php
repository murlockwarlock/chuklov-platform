<?php

namespace App\Modules\Sessions\Application;

use App\Models\User;
use App\Modules\Attachments\Application\AttachmentAuthorization;
use App\Modules\Attachments\Domain\Models\MedicalAttachment;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Security\Application\RecordAuditEvent;
use App\Modules\Sessions\Domain\Models\MedicalSession;
use App\Modules\Sessions\Domain\Models\MedicalSessionAttachment;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final readonly class LinkSessionAttachment
{
    public function __construct(
        private MedicalSessionAuthorization $sessionAuthorization,
        private AttachmentAuthorization $attachmentAuthorization,
        private RecordAuditEvent $audit,
    ) {}

    public function handle(User $actor, MedicalSession $session, Client $client, int $attachmentId): MedicalSessionAttachment
    {
        $organization = $this->sessionAuthorization->authorizeManageSession($actor, $session, $client);
        $this->attachmentAuthorization->authorizeManage($actor, $client);

        $attachment = MedicalAttachment::query()
            ->where('organization_id', $organization->getKey())
            ->where('client_id', $client->getKey())
            ->whereKey($attachmentId)
            ->first();

        if ($attachment === null) {
            throw ValidationException::withMessages([
                'attachment_id' => 'Выбранный файл не принадлежит этому клиенту.',
            ]);
        }

        return DB::transaction(function () use ($actor, $organization, $client, $session, $attachment): MedicalSessionAttachment {
            $link = MedicalSessionAttachment::query()->firstOrCreate([
                'organization_id' => $organization->getKey(),
                'client_id' => $client->getKey(),
                'medical_session_id' => $session->getKey(),
                'medical_attachment_id' => $attachment->getKey(),
            ]);

            if ($link->wasRecentlyCreated) {
                $this->audit->handle(
                    organization: $organization,
                    actor: $actor,
                    action: 'medical.session.attachment.linked',
                    targetType: MedicalSession::class,
                    targetId: (string) $session->getKey(),
                    metadata: ['attachment_id' => $attachment->getKey()],
                );
            }

            return $link;
        });
    }
}
