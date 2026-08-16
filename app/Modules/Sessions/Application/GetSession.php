<?php

namespace App\Modules\Sessions\Application;

use App\Models\User;
use App\Modules\MedicalProfiles\Domain\Contracts\MedicalEncryptorInterface;
use App\Modules\Sessions\Application\DTOs\MedicalSessionData;
use App\Modules\Sessions\Domain\Models\MedicalSession;

final class GetSession
{
    /** @var array<string, ?MedicalSessionData> */
    private array $resolved = [];

    public function __construct(
        private readonly MedicalSessionAuthorization $authorization,
        private readonly MedicalEncryptorInterface $encryptor,
    ) {}

    public function handle(User $actor, MedicalSession $session): ?MedicalSessionData
    {
        $organization = $this->authorization->authorizeView($actor, $session);
        $orgId = (int) $organization->getKey();
        $cacheKey = $actor->getKey().':'.$orgId.':'.$session->getKey();

        if (array_key_exists($cacheKey, $this->resolved)) {
            return $this->resolved[$cacheKey];
        }

        /** @var MedicalSession|null $persisted */
        $persisted = MedicalSession::query()
            ->where('organization_id', $orgId)
            ->where('id', $session->getKey())
            ->first();

        if ($persisted === null) {
            return $this->resolved[$cacheKey] = null;
        }

        $keyVersion = (int) $persisted->encryption_key_version;

        $data = new MedicalSessionData(
            id: (int) $persisted->getKey(),
            organizationId: $orgId,
            clientId: (int) $persisted->client_id,
            specialistId: (int) $persisted->specialist_id,
            bookingId: $persisted->booking_id !== null ? (int) $persisted->booking_id : null,
            pain: $this->encryptor->decryptField($orgId, $persisted->pain, $keyVersion),
            tests: $this->encryptor->decryptField($orgId, $persisted->tests, $keyVersion),
            observations: $this->encryptor->decryptField($orgId, $persisted->observations, $keyVersion),
            rootCauseHypothesis: $this->encryptor->decryptField($orgId, $persisted->root_cause_hypothesis, $keyVersion),
            protocol: $this->encryptor->decryptField($orgId, $persisted->protocol, $keyVersion),
            result: $this->encryptor->decryptField($orgId, $persisted->result, $keyVersion),
            encryptionKeyVersion: $keyVersion,
            occurredAt: $persisted->occurred_at,
            createdAt: $persisted->created_at,
            updatedAt: $persisted->updated_at,
        );

        return $this->resolved[$cacheKey] = $data;
    }

    public function invalidate(?int $actorId = null, ?int $orgId = null, ?int $sessionId = null): void
    {
        if ($actorId !== null && $orgId !== null && $sessionId !== null) {
            unset($this->resolved[$actorId.':'.$orgId.':'.$sessionId]);
        } else {
            $this->resolved = [];
        }
    }
}
