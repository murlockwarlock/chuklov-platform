<?php

namespace App\Modules\Sessions\Application;

use App\Modules\MedicalProfiles\Application\MedicalFrequencyPeriodCatalog;
use App\Modules\MedicalProfiles\Application\MedicalPainInput;
use App\Modules\MedicalProfiles\Application\MedicalProblemTagCatalog;
use App\Modules\MedicalProfiles\Application\MedicalComplaintCatalog;
use App\Modules\MedicalProfiles\Domain\Contracts\MedicalEncryptorInterface;
use App\Modules\Sessions\Domain\Models\MedicalSession;
use App\Modules\Sessions\Domain\Models\MedicalSessionPain;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final readonly class MedicalSessionPainWriter
{
    public function __construct(
        private MedicalEncryptorInterface $encryptor,
        private MedicalFrequencyPeriodCatalog $periods,
        private MedicalProblemTagCatalog $tags,
        private MedicalComplaintCatalog $complaints,
    ) {}

    /** @param array<int, mixed> $rawPains
     * @param  array<string, array{frequency_period_unit: string|null, tag_ids: list<int>}>  $historicalPainValues
     */
    public function replace(MedicalSession $session, array $rawPains, int $keyVersion, bool $allowInactiveFrequencyPeriods = false, bool $allowInactiveProblemTags = false, array $historicalPainValues = []): void
    {
        $existing = $session->pains()->with('problemTags')->lockForUpdate()->get()->keyBy('lineage_key');
        $inputs = [];
        $lineages = [];
        foreach ($rawPains as $rawPain) {
            if (! is_array($rawPain)) {
                throw ValidationException::withMessages(['pains' => 'Проверьте список болей.']);
            }
            if (MedicalPainInput::isEmptyRow($rawPain)) {
                continue;
            }
            $input = MedicalPainInput::fromArray($rawPain);
            if ($input->name === '' && $input->complaintDefinitionId !== null) {
                $input = $input->withName($this->complaints->name((int) $session->organization_id, $input->complaintDefinitionId) ?? '');
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
            $historicalValues = $historicalPainValues[$lineage] ?? null;
            $preservedTagIds = $existingPain instanceof MedicalSessionPain ? $existingPain->problemTags->modelKeys() : [];
            $preservedComplaintIds = $existingPain instanceof MedicalSessionPain && $existingPain->medical_complaint_definition_id !== null
                ? [(int) $existingPain->medical_complaint_definition_id]
                : [];
            if ($allowInactiveProblemTags) {
                $preservedTagIds = [...$preservedTagIds, ...$input->tagIds];
            } elseif (is_array($historicalValues)) {
                $preservedTagIds = [...$preservedTagIds, ...array_intersect($input->tagIds, $historicalValues['tag_ids'])];
            }
            if ($input->frequencyMode === 'count' && $input->frequencyPeriodUnit !== null) {
                $canKeepInactivePeriod = $allowInactiveFrequencyPeriods
                    || (is_array($historicalValues) && $historicalValues['frequency_period_unit'] === $input->frequencyPeriodUnit)
                    || ($existingPain instanceof MedicalSessionPain
                        && $existingPain->frequency_mode === 'count'
                        && $existingPain->frequency_period_unit === $input->frequencyPeriodUnit);
                if (! $canKeepInactivePeriod && ! $this->periods->isActive((int) $session->organization_id, $input->frequencyPeriodUnit)) {
                    $this->periods->assertActive((int) $session->organization_id, $input->frequencyPeriodUnit);
                }
            }
            $inputs[] = [
                $input,
                $lineage,
                $this->tags->assertKnown((int) $session->organization_id, $input->tagIds, $preservedTagIds),
                $this->complaints->assertKnown(
                    (int) $session->organization_id,
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

        foreach ($inputs as $sortOrder => [$input, $lineage, $tagIds, $complaintIds]) {
            $pain = $existing->get($lineage) ?? new MedicalSessionPain;
            $encryptedName = $this->encryptedName($pain, $input->name, (int) $session->organization_id, $keyVersion);
            $pain->forceFill([
                'organization_id' => $session->organization_id,
                'client_id' => $session->client_id,
                'medical_session_id' => $session->getKey(),
                'patient_profile_id' => $session->patient_profile_id,
                'medical_complaint_definition_id' => $complaintIds[0] ?? null,
                'lineage_key' => $lineage,
                'name' => $encryptedName,
                'frequency_mode' => $input->frequencyMode,
                'frequency_count' => $input->frequencyCount,
                'frequency_period_unit' => $input->frequencyPeriodUnit,
                'time_of_day' => $input->timeOfDay,
                'before_score' => $input->beforeScore,
                'after_score' => $input->afterScore,
                'needs_confirmation' => $this->needsConfirmation($existingPain, $input, (int) $session->organization_id),
                'state' => $input->state,
                'sort_order' => $sortOrder,
                'encryption_key_version' => $keyVersion,
            ]);
            $pain->save();
            $pain->problemTags()->syncWithPivotValues($tagIds, [
                'organization_id' => $session->organization_id,
                'client_id' => $session->client_id,
            ]);
        }
    }

    private function encryptedName(MedicalSessionPain $pain, string $name, int $organizationId, int $keyVersion): string
    {
        if ($pain->exists
            && (int) $pain->encryption_key_version === $keyVersion
            && $this->encryptor->decryptField($organizationId, $pain->getRawOriginal('name'), $keyVersion) === $name) {
            return (string) $pain->getRawOriginal('name');
        }

        return (string) $this->encryptor->encryptField($organizationId, $name, $keyVersion);
    }

    private function needsConfirmation(?MedicalSessionPain $existing, MedicalPainInput $input, int $organizationId): bool
    {
        if (! $input->needsConfirmation) {
            return false;
        }
        if (! $existing instanceof MedicalSessionPain || ! $existing->exists) {
            return true;
        }
        if (! (bool) $existing->needs_confirmation) {
            return false;
        }

        $existingName = (string) $this->encryptor->decryptField(
            $organizationId,
            $existing->getRawOriginal('name'),
            (int) $existing->encryption_key_version,
        );

        return $existingName === $input->name
            && $existing->medical_complaint_definition_id === $input->complaintDefinitionId
            && $existing->frequency_mode === $input->frequencyMode
            && $existing->frequency_count === $input->frequencyCount
            && $existing->frequency_period_unit === $input->frequencyPeriodUnit
            && array_values((array) $existing->time_of_day) === $input->timeOfDay
            && $existing->before_score === $input->beforeScore
            && $existing->after_score === $input->afterScore;
    }
}
