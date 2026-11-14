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
        private MedicalProblemTagCatalog $tags,
        private MedicalComplaintCatalog $complaints,
    ) {}

    /**
     * @param  list<mixed>  $rawPains
     * @return list<MedicalPainData>
     */
    public function replace(MedicalProfile $profile, array $rawPains, int $keyVersion): array
    {
        $existing = $profile->pains()->with('problemTags')->lockForUpdate()->get()->keyBy('lineage_key');
        $inputs = [];
        $lineages = [];
        foreach ($rawPains as $rawPain) {
            if (! is_array($rawPain)) {
                throw ValidationException::withMessages(['pains' => 'Проверьте список болей.']);
            }
            if (MedicalPainInput::isEmptyRow($rawPain)) {
                continue;
            }
            $profilePain = $rawPain;
            unset(
                $profilePain['frequency_mode'],
                $profilePain['frequency_count'],
                $profilePain['frequency_period_unit'],
                $profilePain['time_of_day'],
                $profilePain['before_score'],
                $profilePain['after_score'],
            );
            $input = MedicalPainInput::fromArray($profilePain);
            if ($input->name === '' && $input->complaintDefinitionId !== null) {
                $input = $input->withName($this->complaints->name((int) $profile->organization_id, $input->complaintDefinitionId) ?? '');
            }
            if ($input->name === '') {
                throw ValidationException::withMessages(['pains' => 'Укажите жалобу или выберите её из справочника.']);
            }
            $lineage = $input->lineageKey ?? Str::uuid()->toString();
            if (isset($lineages[$lineage])) {
                throw ValidationException::withMessages(['pains' => 'Каждая боль должна быть указана только один раз.']);
            }
            $lineages[$lineage] = true;
            $existingPain = $existing->get($lineage);
            $preservedTagIds = $existingPain instanceof MedicalProfilePain ? $existingPain->problemTags->modelKeys() : [];
            $preservedComplaintIds = $existingPain instanceof MedicalProfilePain && $existingPain->medical_complaint_definition_id !== null
                ? [(int) $existingPain->medical_complaint_definition_id]
                : [];
            $inputs[] = [
                $input,
                $lineage,
                $this->tags->assertKnown((int) $profile->organization_id, $input->tagIds, $preservedTagIds),
                $this->complaints->assertKnown(
                    (int) $profile->organization_id,
                    $input->complaintDefinitionId === null ? [] : [$input->complaintDefinitionId],
                    $preservedComplaintIds,
                ),
            ];
        }

        foreach ($existing as $lineage => $pain) {
            if (! isset($lineages[$lineage])) {
                $pain->delete();
            }
        }

        $result = [];
        foreach ($inputs as $sortOrder => [$input, $lineage, $tagIds, $complaintIds]) {
            /** @var MedicalProfilePain|null $pain */
            $pain = $existing->get($lineage);
            $pain ??= new MedicalProfilePain;
            $encryptedName = $this->encryptedName($pain, $input->name, (int) $profile->organization_id, $keyVersion);
            $pain->forceFill([
                'organization_id' => $profile->organization_id,
                'client_id' => $profile->client_id,
                'medical_profile_id' => $profile->getKey(),
                'patient_profile_id' => $profile->patient_profile_id,
                'medical_complaint_definition_id' => $complaintIds[0] ?? null,
                'lineage_key' => $lineage,
                'name' => $encryptedName,
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
                complaintDefinitionId: $complaintIds[0] ?? null,
                tagIds: $tagIds,
            );
        }

        return $result;
    }

    private function encryptedName(MedicalProfilePain $pain, string $name, int $organizationId, int $keyVersion): string
    {
        if ($pain->exists
            && (int) $pain->encryption_key_version === $keyVersion
            && $this->encryptor->decryptField($organizationId, $pain->getRawOriginal('name'), $keyVersion) === $name) {
            return (string) $pain->getRawOriginal('name');
        }

        return (string) $this->encryptor->encryptField($organizationId, $name, $keyVersion);
    }
}
