<?php

namespace App\Modules\Sessions\Application;

use App\Models\User;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Security\Application\RecordAuditEvent;
use App\Modules\Sessions\Domain\Models\MedicalSession;
use App\Modules\Sessions\Domain\Models\MedicalSessionAttachment;
use Illuminate\Support\Facades\DB;

final readonly class UnlinkSessionAttachment
{
    public function __construct(
        private MedicalSessionAuthorization $authorization,
        private RecordAuditEvent $audit,
    ) {}

    public function handle(User $actor, MedicalSession $session, Client $client, int $attachmentId): bool
    {
        $organization = $this->authorization->authorizeManageSession($actor, $session, $client);

        return DB::transaction(function () use ($actor, $organization, $client, $session, $attachmentId): bool {
            $deleted = MedicalSessionAttachment::query()
                ->where('organization_id', $organization->getKey())
                ->where('client_id', $client->getKey())
                ->where('medical_session_id', $session->getKey())
                ->where('medical_attachment_id', $attachmentId)
                ->delete();

            if ($deleted > 0) {
                $this->audit->handle(
                    organization: $organization,
                    actor: $actor,
                    action: 'medical.session.attachment.unlinked',
                    targetType: MedicalSession::class,
                    targetId: (string) $session->getKey(),
                    metadata: ['attachment_id' => $attachmentId],
                );
            }

            return $deleted > 0;
        });
    }
}
