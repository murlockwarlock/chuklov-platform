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
    public function __construct(
        private MedicalSessionAuthorization $authorization,
        private MedicalEncryptorInterface $encryptor,
        private MedicalKeyResolverInterface $keyResolver,
        private RecordAuditEvent $audit,
        private GetSession $getSession,
        private MedicalSessionSnapshotHasher $snapshotHasher,
        private MedicalSessionPainWriter $painWriter,
        private MedicalSessionPainReader $painReader,
        private MedicalSessionTestWriter $testWriter,
        private MedicalSessionTestReader $testReader,
        private MedicalSessionCauseWriter $causeWriter,
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
            encryptedSubjectiveFeelings: $this->encryptor->encryptField($orgId, $command->subjectiveFeelings, $keyVersion),
            encryptedVisualPostureDiagnostics: $this->encryptor->encryptField($orgId, $command->visualPostureDiagnostics, $keyVersion),
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

            if ($command->expectedSnapshot !== null
                && ! hash_equals($command->expectedSnapshot, $this->snapshotHasher->forSession($existing))) {
                throw ValidationException::withMessages([
                    'session' => 'Медицинский сеанс изменился в другой вкладке. Обновите его перед сохранением.',
                ]);
            }

            $previousKeyVersion = (int) $existing->encryption_key_version;

            $existingPain = $this->encryptor->decryptField($orgId, $existing->pain, $previousKeyVersion);
            $existingTests = $this->encryptor->decryptField($orgId, $existing->tests, $previousKeyVersion);
            $existingObservations = $this->encryptor->decryptField($orgId, $existing->observations, $previousKeyVersion);
            $existingSubjectiveFeelings = $this->encryptor->decryptField($orgId, $existing->subjective_feelings, $previousKeyVersion);
            $existingVisualPostureDiagnostics = $this->encryptor->decryptField($orgId, $existing->visual_posture_diagnostics, $previousKeyVersion);
            $existingRootCauseHypothesis = $this->encryptor->decryptField($orgId, $existing->root_cause_hypothesis, $previousKeyVersion);
            $existingProtocol = $this->encryptor->decryptField($orgId, $existing->protocol, $previousKeyVersion);
            $existingResult = $this->encryptor->decryptField($orgId, $existing->result, $previousKeyVersion);
            $existingPainVas = $this->painVas($this->encryptor->decryptField($orgId, $existing->pain_vas, $previousKeyVersion));
            $painVas = $command->shouldUpdatePainVas() ? $command->painVas : $existingPainVas;
            $subjectiveFeelings = $command->shouldUpdateSubjectiveFeelings() ? $command->subjectiveFeelings : $existingSubjectiveFeelings;
            $visualPostureDiagnostics = $command->shouldUpdateVisualPostureDiagnostics() ? $command->visualPostureDiagnostics : $existingVisualPostureDiagnostics;
            $encryptedPainVas = $this->encryptor->encryptField(
                $orgId,
                $painVas === null ? null : (string) $painVas,
                $keyVersion,
            );

            $updatedFields = $this->diffUpdatedFieldNames(
                previous: [
                    'pain' => $existingPain,
                    'tests' => $existingTests,
                    'observations' => $existingObservations,
                    'subjective_feelings' => $existingSubjectiveFeelings,
                    'visual_posture_diagnostics' => $existingVisualPostureDiagnostics,
                    'root_cause_hypothesis' => $existingRootCauseHypothesis,
                    'protocol' => $existingProtocol,
                    'result' => $existingResult,
                    'pain_vas' => $existingPainVas,
                ],
                command: $command,
                painVas: $painVas,
            );

            $existing->forceFill([
                'pain' => $encrypted->encryptedPain,
                'pain_vas' => $encryptedPainVas,
                'tests' => $encrypted->encryptedTests,
                'observations' => $encrypted->encryptedObservations,
                'subjective_feelings' => $this->encryptor->encryptField($orgId, $subjectiveFeelings, $keyVersion),
                'visual_posture_diagnostics' => $this->encryptor->encryptField($orgId, $visualPostureDiagnostics, $keyVersion),
                'root_cause_hypothesis' => $encrypted->encryptedRootCauseHypothesis,
                'protocol' => $encrypted->encryptedProtocol,
                'result' => $encrypted->encryptedResult,
                'causes_manual_override' => $command->causesManualOverrideProvided
                    ? $command->causesManualOverride
                    : (bool) $existing->causes_manual_override,
                'encryption_key_version' => $keyVersion,
            ]);
            $existing->save();

            if ($command->painsProvided) {
                $this->painWriter->replace($existing, $command->pains ?? [], $keyVersion);
            }
            if ($command->testResultsProvided) {
                $existingSessionCauseIds = $command->causesManualOverrideProvided
                    ? ($command->causesManualOverride ? ($command->causeIds ?? []) : [])
                    : ($existing->causes_manual_override ? $existing->causes()->pluck('medical_cause_definitions.id')->all() : []);
                $this->testWriter->replace(
                    $existing,
                    $command->testResults ?? [],
                    $command->rootCauses ?? [],
                    $keyVersion,
                    $existingSessionCauseIds,
                );
            } elseif ($command->rootCausesProvided) {
                $existingSessionCauseIds = $command->causesManualOverrideProvided
                    ? ($command->causesManualOverride ? ($command->causeIds ?? []) : [])
                    : ($existing->causes_manual_override ? $existing->causes()->pluck('medical_cause_definitions.id')->all() : []);
                $this->testWriter->replaceRootCauses(
                    $existing,
                    $command->rootCauses ?? [],
                    $keyVersion,
                    $existingSessionCauseIds,
                );
            }
            if ($command->causesProvided || $command->causesManualOverrideProvided) {
                $this->causeWriter->replace(
                    $existing,
                    $command->causeIds ?? [],
                    $command->causesManualOverrideProvided ? $command->causesManualOverride : (bool) $existing->causes_manual_override,
                );
            }

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
                painVas: $painVas,
                pains: $this->painReader->forSession($existing),
                subjectiveFeelings: $subjectiveFeelings,
                visualPostureDiagnostics: $visualPostureDiagnostics,
                testResults: $this->testReader->tests($existing),
                rootCauses: $this->testReader->rootCauses($existing),
                causes: $this->testReader->causes($existing),
                causesManualOverride: (bool) $existing->causes_manual_override,
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
            'subjective_feelings' => $command->subjectiveFeelings,
            'visual_posture_diagnostics' => $command->visualPostureDiagnostics,
            'root_cause_hypothesis' => $command->rootCauseHypothesis,
            'protocol' => $command->protocol,
            'result' => $command->result,
        ] as $field => $value) {
            if ($value !== null && mb_strlen($value) > MedicalSessionFieldLimits::MaxTextLength) {
                throw ValidationException::withMessages([
                    $field => 'Поле превышает максимальную длину в '.MedicalSessionFieldLimits::MaxTextLength.' символов.',
                ]);
            }
        }

        if ($command->painVas !== null && ($command->painVas < 0 || $command->painVas > 10)) {
            throw ValidationException::withMessages([
                'pain_vas' => 'Укажите значение боли от 0 до 10.',
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $previous
     * @return list<string>
     */
    private function diffUpdatedFieldNames(array $previous, UpdateSessionCommand $command, ?int $painVas): array
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
        if ($previous['subjective_feelings'] !== ($command->shouldUpdateSubjectiveFeelings() ? $command->subjectiveFeelings : $previous['subjective_feelings'])) {
            $fields[] = 'subjective_feelings';
        }
        if ($previous['visual_posture_diagnostics'] !== ($command->shouldUpdateVisualPostureDiagnostics() ? $command->visualPostureDiagnostics : $previous['visual_posture_diagnostics'])) {
            $fields[] = 'visual_posture_diagnostics';
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
        if ($previous['pain_vas'] !== $painVas) {
            $fields[] = 'pain_vas';
        }
        if ($command->painsProvided) {
            $fields[] = 'pains';
        }
        if ($command->testResultsProvided) {
            $fields[] = 'test_results';
        }
        if ($command->rootCausesProvided) {
            $fields[] = 'root_causes';
        }
        if ($command->causesProvided || $command->causesManualOverrideProvided) {
            $fields[] = 'causes';
        }

        return $fields;
    }

    private function painVas(?string $value): ?int
    {
        return $value !== null && preg_match('/^-?\d+$/D', $value) === 1 ? (int) $value : null;
    }
}
