<?php

namespace App\Modules\MedicalProfiles\Application;

use App\Models\User;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\MedicalProfiles\Application\DTOs\MedicalProfileData;
use App\Modules\MedicalProfiles\Application\DTOs\UpdateMedicalProfileCommand;
use App\Modules\MedicalProfiles\Domain\Contracts\MedicalEncryptorInterface;
use App\Modules\MedicalProfiles\Domain\Contracts\MedicalKeyResolverInterface;
use App\Modules\MedicalProfiles\Domain\Models\MedicalProfile;
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
    ) {}

    public function handle(User $actor, Client $client, UpdateMedicalProfileCommand $command): MedicalProfileData
    {
        $organization = $this->authorization->authorizeManage($actor, $client);
        $orgId = (int) $organization->getKey();

        $this->validateCommand($command);

        $keyVersion = $this->keyResolver->getCurrentKeyVersion($orgId);

        $plainData = new MedicalProfileData(
            anamnesis: $command->anamnesis,
            complaintsGoals: $command->complaintsGoals,
            operationsInjuries: $command->operationsInjuries,
            medicines: $command->medicines,
            supplements: $command->supplements,
            encryptionKeyVersion: $keyVersion,
        );

        $encrypted = $this->encryptor->encryptProfile($orgId, $plainData, $keyVersion);

        $result = DB::transaction(function () use ($actor, $organization, $client, $plainData, $encrypted, $keyVersion, $orgId) {
            /** @var MedicalProfile|null $existing */
            $existing = MedicalProfile::query()
                ->where('organization_id', $orgId)
                ->where('client_id', $client->getKey())
                ->first();

            $isNew = $existing === null;
            $profile = $existing ?? new MedicalProfile;

            $profile->forceFill([
                'organization_id' => $orgId,
                'client_id' => $client->getKey(),
                'anamnesis' => $encrypted->encryptedAnamnesis,
                'complaints_goals' => $encrypted->encryptedComplaintsGoals,
                'operations_injuries' => $encrypted->encryptedOperationsInjuries,
                'medicines' => $encrypted->encryptedMedicines,
                'supplements' => $encrypted->encryptedSupplements,
                'encryption_key_version' => $keyVersion,
            ]);
            $profile->save();

            $updatedFields = $this->collectUpdatedFieldNames($plainData);

            $this->audit->handle(
                organization: $organization,
                actor: $actor,
                action: $isNew ? 'medical.profile.created' : 'medical.profile.updated',
                targetType: MedicalProfile::class,
                targetId: (string) $profile->getKey(),
                metadata: [
                    'source' => 'crm',
                    'key_version' => $keyVersion,
                    'updated_fields' => implode(',', $updatedFields),
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
            );
        });

        $this->getProfile->invalidate($actor->getKey(), $orgId, $client->getKey());

        return $result;
    }

    private function validateCommand(UpdateMedicalProfileCommand $command): void
    {
        $fields = [
            'anamnesis' => $command->anamnesis,
            'complaints_goals' => $command->complaintsGoals,
            'operations_injuries' => $command->operationsInjuries,
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
    private function collectUpdatedFieldNames(MedicalProfileData $data): array
    {
        $fields = [];
        if ($data->anamnesis !== null) {
            $fields[] = 'anamnesis';
        }
        if ($data->complaintsGoals !== null) {
            $fields[] = 'complaints_goals';
        }
        if ($data->operationsInjuries !== null) {
            $fields[] = 'operations_injuries';
        }
        if ($data->medicines !== null) {
            $fields[] = 'medicines';
        }
        if ($data->supplements !== null) {
            $fields[] = 'supplements';
        }

        return $fields;
    }
}
