<?php

namespace App\Modules\Sessions\Application;

use App\Modules\MedicalProfiles\Application\MedicalFrequencyPeriodCatalog;
use App\Modules\MedicalProfiles\Application\MedicalPainInput;
use App\Modules\MedicalProfiles\Application\MedicalProblemTagCatalog;
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
    ) {}

    /** @param array<int, mixed> $rawPains */
    public function replace(MedicalSession $session, array $rawPains, int $keyVersion, bool $allowInactiveFrequencyPeriods = false, bool $allowInactiveProblemTags = false): void
    {
        $existing = $session->pains()->with('problemTags')->lockForUpdate()->get()->keyBy('lineage_key');
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
            $existingPain = $existing->get($lineage);
            $preservedTagIds = $existingPain instanceof MedicalSessionPain ? $existingPain->problemTags->modelKeys() : [];
            if ($allowInactiveProblemTags) {
                $preservedTagIds = [...$preservedTagIds, ...$input->tagIds];
            }
            if ($input->frequencyMode === 'count' && $input->frequencyPeriodUnit !== null) {
                $canKeepInactivePeriod = $allowInactiveFrequencyPeriods
                    || ($existingPain instanceof MedicalSessionPain
                        && $existingPain->frequency_mode === 'count'
                        && $existingPain->frequency_period_unit === $input->frequencyPeriodUnit);
                if (! $canKeepInactivePeriod && ! $this->periods->isActive((int) $session->organization_id, $input->frequencyPeriodUnit)) {
                    $this->periods->assertActive((int) $session->organization_id, $input->frequencyPeriodUnit);
                }
            }
            $inputs[] = [$input, $lineage, $this->tags->assertKnown((int) $session->organization_id, $input->tagIds, $preservedTagIds)];
        }

        foreach ($existing as $lineage => $pain) {
            if (! isset($lineages[$lineage])) {
                $pain->delete();
            }
        }

        foreach ($inputs as $sortOrder => [$input, $lineage, $tagIds]) {
            $pain = $existing->get($lineage) ?? new MedicalSessionPain;
            $pain->forceFill([
                'organization_id' => $session->organization_id,
                'client_id' => $session->client_id,
                'medical_session_id' => $session->getKey(),
                'patient_profile_id' => $session->patient_profile_id,
                'lineage_key' => $lineage,
                'name' => $this->encryptor->encryptField((int) $session->organization_id, $input->name, $keyVersion),
                'frequency_mode' => $input->frequencyMode,
                'frequency_count' => $input->frequencyCount,
                'frequency_period_unit' => $input->frequencyPeriodUnit,
                'time_of_day' => $input->timeOfDay,
                'before_score' => $input->beforeScore,
                'after_score' => $input->afterScore,
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
}
