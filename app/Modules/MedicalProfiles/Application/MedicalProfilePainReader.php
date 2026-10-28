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
        return $profile->pains()->get()->map(function (MedicalProfilePain $pain) use ($profile): MedicalPainData {
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
                timeOfDay: is_array($pain->time_of_day) && $pain->time_of_day !== [] ? array_values($pain->time_of_day) : ['all'],
                score: $pain->score !== null ? (int) $pain->score : null,
            );
        })->all();
    }
}
