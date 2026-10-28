<?php

namespace App\Modules\MedicalProfiles\Application;

use App\Modules\MedicalProfiles\Application\DTOs\MedicalPainData;
use App\Modules\MedicalProfiles\Domain\Contracts\MedicalEncryptorInterface;
use App\Modules\MedicalProfiles\Domain\Models\MedicalProfile;
use App\Modules\MedicalProfiles\Domain\Models\MedicalProfilePain;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final readonly class MedicalProfilePainWriter
{
    public function __construct(
        private MedicalEncryptorInterface $encryptor,
        private MedicalFrequencyPeriodCatalog $periods,
        private MedicalProblemTagCatalog $tags,
    ) {}

    /** @param array<int, mixed> $rawPains */
    /** @return list<MedicalPainData> */
    public function replace(MedicalProfile $profile, array $rawPains, int $keyVersion): array
    {
        $inputs = [];
        $lineages = [];
        foreach ($rawPains as $rawPain) {
            if (! is_array($rawPain)) {
                throw ValidationException::withMessages(['pains' => 'Проверьте список болей.']);
            }
            $input = MedicalPainInput::fromArray($rawPain);
            $lineage = $input->lineageKey ?? Str::uuid()->toString();
            if (isset($lineages[$lineage])) {
                throw ValidationException::withMessages(['pains' => 'Каждая боль должна быть указана только один раз.']);
            }
            $lineages[$lineage] = true;
            if ($input->frequencyMode === 'count' && $input->frequencyPeriodUnit !== null) {
                $this->periods->assertActive((int) $profile->organization_id, $input->frequencyPeriodUnit);
            }
            $inputs[] = [$input, $lineage, $this->tags->assertKnown((int) $profile->organization_id, $input->tagIds)];
        }

        $existing = $profile->pains()->lockForUpdate()->get()->keyBy('lineage_key');
        foreach ($existing as $lineage => $pain) {
            if (! isset($lineages[$lineage])) {
                $pain->delete();
            }
        }

        $result = [];
        foreach ($inputs as $sortOrder => [$input, $lineage, $tagIds]) {
            /** @var MedicalProfilePain|null $pain */
            $pain = $existing->get($lineage);
            $pain ??= new MedicalProfilePain;
            $pain->forceFill([
                'organization_id' => $profile->organization_id,
                'client_id' => $profile->client_id,
                'medical_profile_id' => $profile->getKey(),
                'patient_profile_id' => $profile->patient_profile_id,
                'lineage_key' => $lineage,
                'name' => $this->encryptor->encryptField((int) $profile->organization_id, $input->name, $keyVersion),
                'frequency_mode' => $input->frequencyMode,
                'frequency_count' => $input->frequencyCount,
                'frequency_period_unit' => $input->frequencyPeriodUnit,
                'time_of_day' => $input->timeOfDay,
                'score' => $input->score,
                'sort_order' => $sortOrder,
                'encryption_key_version' => $keyVersion,
            ]);
            $pain->save();
            $pain->problemTags()->syncWithPivotValues($tagIds, [
                'organization_id' => $profile->organization_id,
                'client_id' => $profile->client_id,
            ]);

            $result[] = new MedicalPainData(
                id: (int) $pain->getKey(),
                lineageKey: $lineage,
                name: $input->name,
                frequencyMode: $input->frequencyMode,
                frequencyCount: $input->frequencyCount,
                frequencyPeriodUnit: $input->frequencyPeriodUnit,
                timeOfDay: $input->timeOfDay,
                score: $input->score,
                tagIds: $tagIds,
            );
        }

        return $result;
    }
}
