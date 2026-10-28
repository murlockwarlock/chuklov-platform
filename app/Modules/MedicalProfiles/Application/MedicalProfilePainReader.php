<?php

namespace App\Modules\MedicalProfiles\Application;

use App\Modules\MedicalProfiles\Application\DTOs\MedicalPainData;
use App\Modules\MedicalProfiles\Domain\Contracts\MedicalEncryptorInterface;
use App\Modules\MedicalProfiles\Domain\Models\MedicalProfile;
use App\Modules\MedicalProfiles\Domain\Models\MedicalProfilePain;

final readonly class MedicalProfilePainReader
{
    public function __construct(private MedicalEncryptorInterface $encryptor) {}

    /** @return list<MedicalPainData> */
    public function forProfile(MedicalProfile $profile): array
    {
        return array_values($profile->pains()->with('problemTags')->get()->map(function (MedicalProfilePain $pain) use ($profile): MedicalPainData {
            /** @var mixed $timeOfDay */
            $timeOfDay = $pain->getAttribute('time_of_day');

            return new MedicalPainData(
                id: (int) $pain->getKey(),
                lineageKey: (string) $pain->lineage_key,
                name: (string) $this->encryptor->decryptField(
                    (int) $profile->organization_id,
                    $pain->getRawOriginal('name'),
                    (int) $pain->encryption_key_version,
                ),
                frequencyMode: (string) $pain->frequency_mode,
                frequencyCount: $pain->frequency_count !== null ? (int) $pain->frequency_count : null,
                frequencyPeriodUnit: $pain->frequency_period_unit !== null ? (string) $pain->frequency_period_unit : null,
                timeOfDay: is_array($timeOfDay) && $timeOfDay !== [] ? array_values(array_map('strval', $timeOfDay)) : ['all'],
                score: $pain->score !== null ? (int) $pain->score : null,
                tagIds: array_values(array_map(static fn (int|string $id): int => (int) $id, $pain->problemTags->modelKeys())),
            );
        })->all());
    }
}
