<?php

namespace App\Modules\MedicalProfiles\Application;

use App\Models\User;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\MedicalProfiles\Application\DTOs\MedicalProfileData;
use App\Modules\MedicalProfiles\Application\DTOs\UpdateMedicalProfileCommand;
use App\Modules\MedicalProfiles\Domain\Contracts\MedicalEncryptorInterface;
use App\Modules\MedicalProfiles\Domain\Contracts\MedicalKeyResolverInterface;
use App\Modules\MedicalProfiles\Domain\Models\MedicalProfile;
use App\Modules\MedicalProfiles\Domain\ValueObjects\EncryptedMedicalPayload;
use App\Modules\Security\Application\RecordAuditEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final readonly class UpdateMedicalProfile
{
    private const MAX_FIELD_LENGTH = 10000;

    public function __construct(
        private MedicalProfileAuthorization $authorization,
        private MedicalEncryptorInterface $encryptor,
        private MedicalKeyResolverInterface $keyResolver,
        private RecordAuditEvent $audit,
        private GetMedicalProfile $getProfile,
        private MedicalProfileSnapshotHasher $snapshotHasher,
    ) {}

    public function handle(User $actor, Client $client, UpdateMedicalProfileCommand $command): MedicalProfileData
    {
        $organization = $this->authorization->authorizeManage($actor, $client);
        $orgId = (int) $organization->getKey();

        $this->validateCommand($command);
        $keyVersion = $this->keyResolver->getCurrentKeyVersion($orgId);

        $result = DB::transaction(function () use ($actor, $organization, $client, $command, $keyVersion, $orgId): MedicalProfileData {
            Client::query()
                ->where('organization_id', $orgId)
                ->whereKey($client->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            /** @var MedicalProfile|null $existing */
            $existing = MedicalProfile::query()
                ->where('organization_id', $orgId)
                ->where('client_id', $client->getKey())
                ->lockForUpdate()
                ->first();

            if ($command->expectedSnapshot !== null
                && ! hash_equals($command->expectedSnapshot, (string) $this->snapshotHasher->forProfile($existing))) {
                throw ValidationException::withMessages([
                    'medical_profile' => 'Медицинский профиль изменился в другой вкладке. Обновите его перед сохранением.',
                ]);
            }

            $previous = $existing instanceof MedicalProfile
                ? $this->decryptProfile($orgId, $existing)
                : new MedicalProfileData(
                    anamnesis: null,
                    complaintsGoals: null,
                    operationsInjuries: null,
                    medicines: null,
                    supplements: null,
                );

            $plainData = new MedicalProfileData(
                anamnesis: $command->shouldUpdateAnamnesis() ? $command->anamnesis : $previous->anamnesis,
                complaintsGoals: $command->shouldUpdateComplaintsGoals() ? $command->complaintsGoals : $previous->complaintsGoals,
                operationsInjuries: $command->shouldUpdateOperationsInjuries() ? $command->operationsInjuries : $previous->operationsInjuries,
                medicines: $command->shouldUpdateMedicines() ? $command->medicines : $previous->medicines,
                supplements: $command->shouldUpdateSupplements() ? $command->supplements : $previous->supplements,
                encryptionKeyVersion: $keyVersion,
                complaints: $command->shouldUpdateComplaints() ? $command->complaints : $previous->complaints,
                goals: $command->shouldUpdateGoals() ? $command->goals : $previous->goals,
                operations: $command->shouldUpdateOperations() ? $command->operations : $previous->operations,
                injuries: $command->shouldUpdateInjuries() ? $command->injuries : $previous->injuries,
            );
            $encrypted = $this->encryptor->encryptProfile($orgId, $plainData, $keyVersion);

            $isNew = $existing === null;
            $profile = $existing ?? new MedicalProfile;
            $profile->forceFill([
                'organization_id' => $orgId,
                'client_id' => $client->getKey(),
                'anamnesis' => $encrypted->encryptedAnamnesis,
                'complaints_goals' => $encrypted->encryptedComplaintsGoals,
                'operations_injuries' => $encrypted->encryptedOperationsInjuries,
                'complaints' => $encrypted->encryptedComplaints,
                'goals' => $encrypted->encryptedGoals,
                'operations' => $encrypted->encryptedOperations,
                'injuries' => $encrypted->encryptedInjuries,
                'medicines' => $encrypted->encryptedMedicines,
                'supplements' => $encrypted->encryptedSupplements,
                'encryption_key_version' => $keyVersion,
            ]);
            $profile->save();

            $this->audit->handle(
                organization: $organization,
                actor: $actor,
                action: $isNew ? 'medical.profile.created' : 'medical.profile.updated',
                targetType: MedicalProfile::class,
                targetId: (string) $profile->getKey(),
                metadata: [
                    'source' => 'crm',
                    'key_version' => $keyVersion,
                    'updated_fields' => implode(',', $this->collectUpdatedFieldNames($command)),
                ],
            );

            return new MedicalProfileData(
                anamnesis: $plainData->anamnesis,
                complaintsGoals: $plainData->complaintsGoals,
                operationsInjuries: $plainData->operationsInjuries,
                medicines: $plainData->medicines,
                supplements: $plainData->supplements,
                encryptionKeyVersion: $keyVersion,
                updatedAt: $profile->updated_at,
                complaints: $plainData->complaints,
                goals: $plainData->goals,
                operations: $plainData->operations,
                injuries: $plainData->injuries,
            );
        });

        $this->getProfile->invalidate($actor->getKey(), $orgId, $client->getKey());

        return $result;
    }

    private function decryptProfile(int $organizationId, MedicalProfile $profile): MedicalProfileData
    {
        return $this->encryptor->decryptProfile(
            $organizationId,
            (int) $profile->encryption_key_version,
            new EncryptedMedicalPayload(
                encryptedAnamnesis: $profile->getRawOriginal('anamnesis'),
                encryptedComplaintsGoals: $profile->getRawOriginal('complaints_goals'),
                encryptedOperationsInjuries: $profile->getRawOriginal('operations_injuries'),
                encryptedMedicines: $profile->getRawOriginal('medicines'),
                encryptedSupplements: $profile->getRawOriginal('supplements'),
                keyVersion: (int) $profile->encryption_key_version,
                encryptedComplaints: $profile->getRawOriginal('complaints'),
                encryptedGoals: $profile->getRawOriginal('goals'),
                encryptedOperations: $profile->getRawOriginal('operations'),
                encryptedInjuries: $profile->getRawOriginal('injuries'),
            ),
        );
    }

    private function validateCommand(UpdateMedicalProfileCommand $command): void
    {
        $fields = [
            'anamnesis' => $command->anamnesis,
            'complaints_goals' => $command->complaintsGoals,
            'operations_injuries' => $command->operationsInjuries,
            'complaints' => $command->complaints,
            'goals' => $command->goals,
            'operations' => $command->operations,
            'injuries' => $command->injuries,
            'medicines' => $command->medicines,
            'supplements' => $command->supplements,
        ];

        foreach ($fields as $field => $value) {
            if ($value !== null && mb_strlen($value) > self::MAX_FIELD_LENGTH) {
                throw ValidationException::withMessages([
                    $field => 'Поле превышает максимальную длину в '.self::MAX_FIELD_LENGTH.' символов.',
                ]);
            }
        }
    }

    /** @return list<string> */
    private function collectUpdatedFieldNames(UpdateMedicalProfileCommand $command): array
    {
        $fields = [];

        if ($command->shouldUpdateAnamnesis()) {
            $fields[] = 'anamnesis';
        }
        if ($command->shouldUpdateComplaintsGoals()) {
            $fields[] = 'complaints_goals';
        }
        if ($command->shouldUpdateOperationsInjuries()) {
            $fields[] = 'operations_injuries';
        }
        if ($command->shouldUpdateComplaints()) {
            $fields[] = 'complaints';
        }
        if ($command->shouldUpdateGoals()) {
            $fields[] = 'goals';
        }
        if ($command->shouldUpdateOperations()) {
            $fields[] = 'operations';
        }
        if ($command->shouldUpdateInjuries()) {
            $fields[] = 'injuries';
        }
        if ($command->shouldUpdateMedicines()) {
            $fields[] = 'medicines';
        }
        if ($command->shouldUpdateSupplements()) {
            $fields[] = 'supplements';
        }

        return $fields;
    }
}
