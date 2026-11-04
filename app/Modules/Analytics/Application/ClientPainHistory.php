<?php

namespace App\Modules\Analytics\Application;

use App\Models\User;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\MedicalProfiles\Domain\Contracts\MedicalEncryptorInterface;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use App\Modules\Sessions\Domain\Models\MedicalSession;

final readonly class ClientPainHistory
{
    public function __construct(
        private OrganizationContext $context,
        private OrganizationAuthorizer $authorizer,
        private MedicalEncryptorInterface $encryptor,
    ) {}

    /** @return list<array{session_id:int, session_number:int, occurred_at:string, pain:string, before_score:int|null, after_score:int|null}> */
    public function handle(User $actor, Client $client): array
    {
        $organization = $this->context->organization();
        $this->authorizer->authorize($actor, $organization, OrganizationPermission::ViewClients);
        $organizationId = (int) $organization->getKey();

        if ((int) $client->organization_id !== $organizationId) {
            abort(404);
        }

        $timezone = $this->context->defaultTimezone();
        $sessions = MedicalSession::query()
            ->where('organization_id', $organizationId)
            ->where('client_id', $client->getKey())
            ->with('pains')
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->get();

        $history = [];
        foreach ($sessions as $index => $session) {
            foreach ($session->pains as $pain) {
                $history[] = [
                    'session_id' => (int) $session->getKey(),
                    'session_number' => $index + 1,
                    'occurred_at' => $session->occurred_at->copy()->setTimezone($timezone)->format('d.m.Y H:i'),
                    'pain' => (string) $this->encryptor->decryptField(
                        $organizationId,
                        $pain->getRawOriginal('name'),
                        (int) $pain->encryption_key_version,
                    ),
                    'before_score' => $pain->before_score !== null ? (int) $pain->before_score : null,
                    'after_score' => $pain->after_score !== null ? (int) $pain->after_score : null,
                ];
            }
        }

        return $history;
    }
}
