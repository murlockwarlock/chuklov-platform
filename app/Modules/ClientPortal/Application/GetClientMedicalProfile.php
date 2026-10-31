<?php

namespace App\Modules\ClientPortal\Application;

use App\Modules\Identity\Application\ResolvePatientClinicalClient;
use App\Modules\MedicalProfiles\Application\MedicalProfileHistoryReader;
use App\Modules\MedicalProfiles\Application\MedicalProfilePainReader;
use App\Modules\MedicalProfiles\Application\MedicalProfileSnapshotHasher;
use App\Modules\MedicalProfiles\Domain\Contracts\MedicalEncryptorInterface;
use App\Modules\MedicalProfiles\Domain\Models\MedicalProfile;
use App\Modules\MedicalProfiles\Domain\ValueObjects\EncryptedMedicalPayload;

final readonly class GetClientMedicalProfile
{
    public function __construct(
        private ClientPortalContext $clientContext,
        private MedicalEncryptorInterface $encryptor,
        private MedicalProfileSnapshotHasher $snapshots,
        private MedicalProfilePainReader $painReader,
        private MedicalProfileHistoryReader $historyReader,
        private ResolvePatientClinicalClient $patients,
    ) {}

    /** @return array<string, mixed> */
    public function handle(?int $patientId = null): array
    {
        $client = $this->patients->handle($this->clientContext->client(), $patientId);
        $profile = MedicalProfile::query()
            ->where('organization_id', $client->organization_id)
            ->where('client_id', $client->getKey())
            ->first();

        if ($profile === null) {
            return [
                'available' => false,
                'updatedAt' => null,
                'anamnesis' => null,
                'complaintsGoals' => null,
                'operationsInjuries' => null,
                'medicines' => null,
                'supplements' => null,
                'expectedSnapshot' => $this->snapshots->forProfile(null),
                'readFailed' => false,
                'fields' => [],
                'answers' => [],
                'legacyFields' => [],
                'pains' => [],
                'historyEntries' => [],
            ];
        }

        try {
            $data = $this->encryptor->decryptProfile(
                (int) $client->organization_id,
                (int) $profile->encryption_key_version,
                new EncryptedMedicalPayload(
                    encryptedAnamnesis: $profile->anamnesis,
                    encryptedComplaintsGoals: $profile->complaints_goals,
                    encryptedOperationsInjuries: $profile->operations_injuries,
                    encryptedMedicines: $profile->medicines,
                    encryptedSupplements: $profile->supplements,
                    keyVersion: (int) $profile->encryption_key_version,
                    encryptedComplaints: $profile->complaints,
                    encryptedGoals: $profile->goals,
                    encryptedOperations: $profile->operations,
                    encryptedInjuries: $profile->injuries,
                    encryptedChronicConditions: $profile->chronic_conditions,
                    encryptedClinicalAnswers: $profile->clinical_answers,
                ),
            );
            $answers = $data->clinicalAnswers === null ? [] : json_decode($data->clinicalAnswers, true, 16, JSON_THROW_ON_ERROR);
            if (! is_array($answers)) {
                throw new \UnexpectedValueException('Invalid clinical questionnaire shape.');
            }
        } catch (\Throwable) {
            return [
                'available' => false,
                'updatedAt' => null,
                'anamnesis' => null,
                'complaintsGoals' => null,
                'operationsInjuries' => null,
                'medicines' => null,
                'supplements' => null,
                'expectedSnapshot' => null,
                'readFailed' => true,
                'fields' => [],
                'answers' => [],
                'legacyFields' => [],
                'pains' => [],
                'historyEntries' => [],
            ];
        }

        return [
            'available' => true,
            'updatedAt' => $profile->updated_at->toIso8601String(),
            'anamnesis' => null,
            'complaintsGoals' => $data->complaintsGoalsForCompatibility(),
            'operationsInjuries' => $data->operationsInjuriesForCompatibility(),
            'medicines' => $data->medicines,
            'supplements' => $data->supplements,
            'expectedSnapshot' => $this->snapshots->forProfile($profile),
            'readFailed' => false,
            'fields' => [
                'complaints' => $data->complaints,
                'goals' => $data->goals,
                'operations' => $data->operations,
                'injuries' => $data->injuries,
                'chronic_conditions' => $data->chronicConditions,
                'medicines' => $data->medicines,
                'supplements' => $data->supplements,
            ],
            'answers' => $answers,
            'legacyFields' => array_filter([
                'complaintsGoals' => ! filled($data->complaints) && ! filled($data->goals) ? $data->complaintsGoals : null,
                'operationsInjuries' => ! filled($data->operations) && ! filled($data->injuries) ? $data->operationsInjuries : null,
            ], fn (?string $value): bool => filled($value)),
            'pains' => array_map(static fn ($pain): array => $pain->toArray(), $this->painReader->forProfile($profile)),
            'historyEntries' => array_map(static fn ($entry): array => $entry->toArray(), $this->historyReader->forProfile($profile)),
        ];
    }
}
