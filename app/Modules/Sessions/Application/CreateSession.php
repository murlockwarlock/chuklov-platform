<?php

namespace App\Modules\Sessions\Application;

use App\Models\User;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\MedicalProfiles\Domain\Contracts\MedicalEncryptorInterface;
use App\Modules\MedicalProfiles\Domain\Contracts\MedicalKeyResolverInterface;
use App\Modules\Scheduling\Domain\Models\Booking;
use App\Modules\Security\Application\RecordAuditEvent;
use App\Modules\Sessions\Application\DTOs\CreateSessionCommand;
use App\Modules\Sessions\Application\DTOs\MedicalSessionData;
use App\Modules\Sessions\Domain\Models\MedicalSession;
use App\Modules\Sessions\Domain\ValueObjects\EncryptedSessionPayload;
use App\Modules\Specialists\Domain\Models\Specialist;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final readonly class CreateSession
{
    private const MAX_FIELD_LENGTH = 10000;

    public function __construct(
        private MedicalSessionAuthorization $authorization,
        private MedicalEncryptorInterface $encryptor,
        private MedicalKeyResolverInterface $keyResolver,
        private RecordAuditEvent $audit,
    ) {}

    public function handle(User $actor, Client $client, CreateSessionCommand $command): MedicalSessionData
    {
        $organization = $this->authorization->authorizeManage($actor, $client);
        $orgId = (int) $organization->getKey();

        $this->validateCommand($command);

        $specialist = Specialist::query()
            ->where('organization_id', $orgId)
            ->where('id', $command->specialistId)
            ->firstOrFail();

        $booking = $this->resolveBooking($orgId, $command, $client, $specialist);

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

        $occurredAtUtc = $command->occurredAtUtc();

        return DB::transaction(function () use ($actor, $organization, $client, $specialist, $booking, $command, $encrypted, $keyVersion, $occurredAtUtc, $orgId): MedicalSessionData {
            $session = new MedicalSession;
            $session->forceFill([
                'organization_id' => $orgId,
                'client_id' => $client->getKey(),
                'specialist_id' => $specialist->getKey(),
                'booking_id' => $booking?->getKey(),
                'pain' => $encrypted->encryptedPain,
                'tests' => $encrypted->encryptedTests,
                'observations' => $encrypted->encryptedObservations,
                'root_cause_hypothesis' => $encrypted->encryptedRootCauseHypothesis,
                'protocol' => $encrypted->encryptedProtocol,
                'result' => $encrypted->encryptedResult,
                'encryption_key_version' => $keyVersion,
                'occurred_at' => $occurredAtUtc,
            ]);
            $session->save();

            $this->audit->handle(
                organization: $organization,
                actor: $actor,
                action: 'medical.session.created',
                targetType: MedicalSession::class,
                targetId: (string) $session->getKey(),
                metadata: [
                    'source' => 'crm',
                    'key_version' => $keyVersion,
                    'booking_id' => $booking?->getKey(),
                    'client_id' => $client->getKey(),
                    'specialist_id' => $specialist->getKey(),
                ],
            );

            return new MedicalSessionData(
                id: (int) $session->getKey(),
                organizationId: $orgId,
                clientId: (int) $client->getKey(),
                specialistId: (int) $specialist->getKey(),
                bookingId: $booking?->getKey(),
                pain: $command->pain,
                tests: $command->tests,
                observations: $command->observations,
                rootCauseHypothesis: $command->rootCauseHypothesis,
                protocol: $command->protocol,
                result: $command->result,
                encryptionKeyVersion: $keyVersion,
                occurredAt: $session->occurred_at,
                createdAt: $session->created_at,
                updatedAt: $session->updated_at,
            );
        });
    }

    private function resolveBooking(int $orgId, CreateSessionCommand $command, Client $client, Specialist $specialist): ?Booking
    {
        if ($command->bookingId === null) {
            return null;
        }

        /** @var Booking|null $booking */
        $booking = Booking::query()
            ->where('organization_id', $orgId)
            ->where('id', $command->bookingId)
            ->first();

        if ($booking === null) {
            throw ValidationException::withMessages([
                'booking_id' => 'The referenced booking does not belong to the current organization.',
            ]);
        }

        if ((int) $booking->client_id !== (int) $client->getKey()) {
            throw ValidationException::withMessages([
                'booking_id' => 'The referenced booking does not belong to the provided client.',
            ]);
        }

        if ((int) $booking->specialist_id !== (int) $specialist->getKey()) {
            throw ValidationException::withMessages([
                'booking_id' => 'The referenced booking does not belong to the assigned specialist.',
            ]);
        }

        return $booking;
    }

    private function validateCommand(CreateSessionCommand $command): void
    {
        if ($command->clientId <= 0) {
            throw ValidationException::withMessages([
                'client_id' => 'A valid client identifier is required.',
            ]);
        }

        if ($command->specialistId <= 0) {
            throw ValidationException::withMessages([
                'specialist_id' => 'A valid specialist identifier is required.',
            ]);
        }

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
}
