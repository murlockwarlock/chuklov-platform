<?php

namespace App\Modules\Sessions\Application;

use App\Models\User;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\MedicalProfiles\Domain\Contracts\MedicalEncryptorInterface;
use App\Modules\MedicalProfiles\Domain\Contracts\MedicalKeyResolverInterface;
use App\Modules\Security\Application\RecordAuditEvent;
use App\Modules\Sessions\Application\DTOs\MedicalSessionData;
use App\Modules\Sessions\Application\DTOs\UpdateSessionCommand;
use App\Modules\Sessions\Domain\Models\MedicalSession;
use App\Modules\Sessions\Domain\ValueObjects\EncryptedSessionPayload;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final readonly class UpdateSession
{
    private const MAX_FIELD_LENGTH = 10000;

    public function __construct(
        private MedicalSessionAuthorization $authorization,
        private MedicalEncryptorInterface $encryptor,
        private MedicalKeyResolverInterface $keyResolver,
        private RecordAuditEvent $audit,
        private GetSession $getSession,
    ) {}

    public function handle(User $actor, MedicalSession $session, UpdateSessionCommand $command, ?Client $expectedClient = null): MedicalSessionData
    {
        $organization = $this->authorization->authorizeManageSession($actor, $session, $expectedClient);
        $orgId = (int) $organization->getKey();

        $this->validateCommand($command);

        $keyVersion = $this->keyResolver->getCurrentKeyVersion($orgId);

        $encrypted = new EncryptedSessionPayload(
            encryptedPain: $this->encryptor->encryptField($orgId, $command->pain, $keyVersion),
            encryptedTests: $this->encryptor->encryptField($orgId, $command->tests, $keyVersion),
            encryptedObservations: $this->encryptor->encryptField($orgId, $command->observations, $keyVersion),
            encryptedRootCauseHypothesis: $this->encryptor->encryptField($orgId, $command->rootCauseHypothesis, $keyVersion),
            encryptedProtocol: $this->encryptor->encryptField($orgId, $command->protocol, $keyVersion),
            encryptedResult: $this->encryptor->encryptField($orgId, $command->result, $keyVersion),
            keyVersion: $keyVersion,
        );

        $result = DB::transaction(function () use ($actor, $organization, $session, $command, $encrypted, $keyVersion, $orgId): MedicalSessionData {
            /** @var MedicalSession|null $existing */
            $existing = MedicalSession::query()
                ->where('organization_id', $orgId)
                ->where('id', $session->getKey())
                ->lockForUpdate()
                ->first();

            if ($existing === null) {
                throw ValidationException::withMessages([
                    'session' => 'The medical session was not found within the current organization.',
                ]);
            }

            $previousKeyVersion = (int) $existing->encryption_key_version;

            $existingPain = $this->encryptor->decryptField($orgId, $existing->pain, $previousKeyVersion);
            $existingTests = $this->encryptor->decryptField($orgId, $existing->tests, $previousKeyVersion);
            $existingObservations = $this->encryptor->decryptField($orgId, $existing->observations, $previousKeyVersion);
            $existingRootCauseHypothesis = $this->encryptor->decryptField($orgId, $existing->root_cause_hypothesis, $previousKeyVersion);
            $existingProtocol = $this->encryptor->decryptField($orgId, $existing->protocol, $previousKeyVersion);
            $existingResult = $this->encryptor->decryptField($orgId, $existing->result, $previousKeyVersion);

            $updatedFields = $this->diffUpdatedFieldNames(
                previous: [
                    'pain' => $existingPain,
                    'tests' => $existingTests,
                    'observations' => $existingObservations,
                    'root_cause_hypothesis' => $existingRootCauseHypothesis,
                    'protocol' => $existingProtocol,
                    'result' => $existingResult,
                ],
                command: $command,
            );

            $existing->forceFill([
                'pain' => $encrypted->encryptedPain,
                'tests' => $encrypted->encryptedTests,
                'observations' => $encrypted->encryptedObservations,
                'root_cause_hypothesis' => $encrypted->encryptedRootCauseHypothesis,
                'protocol' => $encrypted->encryptedProtocol,
                'result' => $encrypted->encryptedResult,
                'encryption_key_version' => $keyVersion,
            ]);
            $existing->save();

            $this->audit->handle(
                organization: $organization,
                actor: $actor,
                action: 'medical.session.updated',
                targetType: MedicalSession::class,
                targetId: (string) $existing->getKey(),
                metadata: [
                    'source' => 'crm',
                    'key_version' => $keyVersion,
                    'updated_fields' => implode(',', $updatedFields),
                ],
            );

            return new MedicalSessionData(
                id: (int) $existing->getKey(),
                organizationId: $orgId,
                clientId: (int) $existing->client_id,
                specialistId: (int) $existing->specialist_id,
                bookingId: $existing->booking_id !== null ? (int) $existing->booking_id : null,
                pain: $command->pain,
                tests: $command->tests,
                observations: $command->observations,
                rootCauseHypothesis: $command->rootCauseHypothesis,
                protocol: $command->protocol,
                result: $command->result,
                encryptionKeyVersion: $keyVersion,
                occurredAt: $existing->occurred_at,
                createdAt: $existing->created_at,
                updatedAt: $existing->updated_at,
            );
        });

        $this->getSession->invalidate($actor->getKey(), $orgId, $session->getKey());

        return $result;
    }

    private function validateCommand(UpdateSessionCommand $command): void
    {
        foreach ([
            'pain' => $command->pain,
            'tests' => $command->tests,
            'observations' => $command->observations,
            'root_cause_hypothesis' => $command->rootCauseHypothesis,
            'protocol' => $command->protocol,
            'result' => $command->result,
        ] as $field => $value) {
            if ($value !== null && mb_strlen($value) > self::MAX_FIELD_LENGTH) {
                throw ValidationException::withMessages([
                    $field => 'Поле превышает максимальную длину в '.self::MAX_FIELD_LENGTH.' символов.',
                ]);
            }
        }
    }

    /**
     * @param  array<string, string|null>  $previous
     * @return list<string>
     */
    private function diffUpdatedFieldNames(array $previous, UpdateSessionCommand $command): array
    {
        $fields = [];

        if ($previous['pain'] !== $command->pain) {
            $fields[] = 'pain';
        }
        if ($previous['tests'] !== $command->tests) {
            $fields[] = 'tests';
        }
        if ($previous['observations'] !== $command->observations) {
            $fields[] = 'observations';
        }
        if ($previous['root_cause_hypothesis'] !== $command->rootCauseHypothesis) {
            $fields[] = 'root_cause_hypothesis';
        }
        if ($previous['protocol'] !== $command->protocol) {
            $fields[] = 'protocol';
        }
        if ($previous['result'] !== $command->result) {
            $fields[] = 'result';
        }

        return $fields;
    }
}
