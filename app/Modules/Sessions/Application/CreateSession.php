<?php

namespace App\Modules\Sessions\Application;

use App\Models\User;
use App\Modules\Identity\Application\EnsureSelfPatientProfile;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\MedicalProfiles\Application\GetMedicalProfile;
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
        private MedicalSessionPainWriter $painWriter,
        private MedicalSessionPainInitializer $painInitializer,
        private MedicalSessionPainReader $painReader,
        private MedicalSessionTestWriter $testWriter,
        private MedicalSessionTestReader $testReader,
        private MedicalSessionCauseWriter $causeWriter,
    ) {}

    public function handle(User $actor, Client $client, CreateSessionCommand $command): MedicalSessionData
    {
        $organization = $this->authorization->authorizeManage($actor, $client);
        $orgId = (int) $organization->getKey();

        $this->validateCommand($command);

        $specialist = Specialist::query()
            ->where('organization_id', $orgId)
            ->where('id', $command->specialistId)
            ->first();

        if ($specialist === null) {
            throw ValidationException::withMessages([
                'specialist_id' => 'Выбранный специалист недоступен.',
            ]);
        }

        $booking = $this->resolveBooking($orgId, $command, $client, $specialist);
        $specialComplaints = $this->initialSpecialComplaints($actor, $client, $command->pain);

        $keyVersion = $this->keyResolver->getCurrentKeyVersion($orgId);

        $encrypted = new EncryptedSessionPayload(
            encryptedPain: $this->encryptor->encryptField($orgId, $specialComplaints, $keyVersion),
            encryptedTests: $this->encryptor->encryptField($orgId, $command->tests, $keyVersion),
            encryptedObservations: $this->encryptor->encryptField($orgId, $command->observations, $keyVersion),
            encryptedSubjectiveFeelings: $this->encryptor->encryptField($orgId, $command->subjectiveFeelings, $keyVersion),
            encryptedVisualPostureDiagnostics: $this->encryptor->encryptField($orgId, $command->visualPostureDiagnostics, $keyVersion),
            encryptedRootCauseHypothesis: $this->encryptor->encryptField($orgId, $command->rootCauseHypothesis, $keyVersion),
            encryptedProtocol: $this->encryptor->encryptField($orgId, $command->protocol, $keyVersion),
            encryptedResult: $this->encryptor->encryptField($orgId, $command->result, $keyVersion),
            keyVersion: $keyVersion,
            encryptedPainVas: $this->encryptor->encryptField(
                $orgId,
                $command->painVas === null ? null : (string) $command->painVas,
                $keyVersion,
            ),
        );

        $occurredAtUtc = $command->occurredAtUtc();

        return DB::transaction(function () use ($actor, $organization, $client, $specialist, $booking, $command, $specialComplaints, $encrypted, $keyVersion, $occurredAtUtc, $orgId): MedicalSessionData {
            $session = new MedicalSession;
            $session->forceFill([
                'organization_id' => $orgId,
                'client_id' => $client->getKey(),
                'patient_profile_id' => app(EnsureSelfPatientProfile::class)->handle($client)->getKey(),
                'specialist_id' => $specialist->getKey(),
                'booking_id' => $booking?->getKey(),
                'pain' => $encrypted->encryptedPain,
                'pain_vas' => $encrypted->encryptedPainVas,
                'tests' => $encrypted->encryptedTests,
                'observations' => $encrypted->encryptedObservations,
                'subjective_feelings' => $encrypted->encryptedSubjectiveFeelings,
                'visual_posture_diagnostics' => $encrypted->encryptedVisualPostureDiagnostics,
                'root_cause_hypothesis' => $encrypted->encryptedRootCauseHypothesis,
                'protocol' => $encrypted->encryptedProtocol,
                'result' => $encrypted->encryptedResult,
                'causes_manual_override' => $command->causesManualOverride,
                'encryption_key_version' => $keyVersion,
                'occurred_at' => $occurredAtUtc,
            ]);
            $session->save();

            if ($command->pains !== null) {
                $this->painWriter->replace($session, $command->pains, $keyVersion);
            } else {
                $this->painInitializer->initialize($session, $keyVersion);
            }
            if ($command->testResults !== null || $command->rootCauses !== null) {
                $this->testWriter->replace(
                    $session,
                    $command->testResults ?? [],
                    $command->rootCauses ?? [],
                    $keyVersion,
                    $command->causesManualOverride ? ($command->causeIds ?? []) : [],
                );
            }
            if ($command->causeIds !== null || $command->causesManualOverride) {
                $this->causeWriter->replace($session, $command->causeIds ?? [], $command->causesManualOverride);
            }

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
                pain: $specialComplaints,
                tests: $command->tests,
                observations: $command->observations,
                rootCauseHypothesis: $command->rootCauseHypothesis,
                protocol: $command->protocol,
                result: $command->result,
                encryptionKeyVersion: $keyVersion,
                occurredAt: $session->occurred_at,
                createdAt: $session->created_at,
                updatedAt: $session->updated_at,
                painVas: $command->painVas,
                pains: $this->painReader->forSession($session),
                subjectiveFeelings: $command->subjectiveFeelings,
                visualPostureDiagnostics: $command->visualPostureDiagnostics,
                testResults: $this->testReader->tests($session),
                rootCauses: $this->testReader->rootCauses($session),
                causes: $this->testReader->causes($session),
                causesManualOverride: (bool) $session->causes_manual_override,
            );
        });
    }

    private function initialSpecialComplaints(User $actor, Client $client, ?string $pain): ?string
    {
        if ($pain !== null || MedicalSession::query()
            ->where('organization_id', $client->organization_id)
            ->where('client_id', $client->getKey())
            ->exists()) {
            return $pain;
        }

        return app(GetMedicalProfile::class)->handle($actor, $client)?->complaints;
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

        if ($booking->clinicalClientId() !== (int) $client->getKey()) {
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
        if ($command->specialistId <= 0) {
            throw ValidationException::withMessages([
                'specialist_id' => 'A valid specialist identifier is required.',
            ]);
        }

        foreach ([
            'pain' => $command->pain,
            'tests' => $command->tests,
            'observations' => $command->observations,
            'subjective_feelings' => $command->subjectiveFeelings,
            'visual_posture_diagnostics' => $command->visualPostureDiagnostics,
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

        if ($command->painVas !== null && ($command->painVas < 0 || $command->painVas > 10)) {
            throw ValidationException::withMessages([
                'pain_vas' => 'Укажите значение боли от 0 до 10.',
            ]);
        }
    }
}
