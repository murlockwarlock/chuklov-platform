<?php

namespace App\Modules\MedicalProfiles\Application;

use App\Modules\MedicalProfiles\Application\DTOs\MedicalHistoryEntryData;
use App\Modules\MedicalProfiles\Domain\Contracts\MedicalEncryptorInterface;
use App\Modules\MedicalProfiles\Domain\Models\MedicalProfile;
use App\Modules\MedicalProfiles\Domain\Models\MedicalProfileHistoryEntry;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final readonly class MedicalProfileHistoryWriter
{
    public function __construct(private MedicalEncryptorInterface $encryptor) {}

    /**
     * @param  array<int, mixed>  $entries
     * @return list<MedicalHistoryEntryData>
     */
    public function replace(MedicalProfile $profile, array $entries, int $keyVersion): array
    {
        $normalized = [];
        foreach (array_values($entries) as $index => $raw) {
            if (! is_array($raw)) {
                throw ValidationException::withMessages(['history_entries' => 'Проверьте строки травм и операций.']);
            }
            $type = (string) ($raw['type'] ?? '');
            $year = filter_var($raw['year'] ?? null, FILTER_VALIDATE_INT);
            $description = trim((string) ($raw['description'] ?? ''));
            if (! in_array($type, ['injury', 'operation'], true)) {
                throw ValidationException::withMessages(['history_entries' => 'Укажите тип медицинской записи.']);
            }
            if (! is_int($year) || $year < 1800 || $year > 2200 || $description === '' || mb_strlen($description) > 10000) {
                throw ValidationException::withMessages(['history_entries' => 'Укажите корректный год и описание.']);
            }
            $lineage = (string) ($raw['lineage_key'] ?? '');
            if (! Str::isUuid($lineage)) {
                $lineage = (string) Str::uuid();
            }
            $normalized[] = compact('type', 'year', 'description', 'lineage') + ['sort_order' => $index];
        }

        $keys = [];
        foreach ($normalized as $entry) {
            $key = $entry['type'].'|'.$entry['lineage'];
            if (isset($keys[$key])) {
                throw ValidationException::withMessages(['history_entries' => 'Строки медицинской истории должны быть уникальными.']);
            }
            $keys[$key] = true;
        }

        $existingRows = $profile->historyEntries()->get();
        foreach ($existingRows as $existingRow) {
            if (! isset($keys[$existingRow->entry_type.'|'.$existingRow->lineage_key])) {
                $existingRow->delete();
            }
        }
        $existing = $existingRows->keyBy(fn (MedicalProfileHistoryEntry $entry): string => $entry->entry_type.'|'.$entry->lineage_key);
        $saved = [];
        foreach ($normalized as $entry) {
            $key = $entry['type'].'|'.$entry['lineage'];
            $model = $existing->get($key) ?? new MedicalProfileHistoryEntry;
            $model->forceFill([
                'organization_id' => $profile->organization_id,
                'client_id' => $profile->client_id,
                'medical_profile_id' => $profile->getKey(),
                'lineage_key' => $entry['lineage'],
                'entry_type' => $entry['type'],
                'year' => $entry['year'],
                'description' => $this->encryptor->encryptField((int) $profile->organization_id, $entry['description'], $keyVersion),
                'encryption_key_version' => $keyVersion,
                'sort_order' => $entry['sort_order'],
            ]);
            $model->save();
            $saved[] = new MedicalHistoryEntryData(
                id: (int) $model->getKey(),
                lineageKey: $entry['lineage'],
                type: $entry['type'],
                year: $entry['year'],
                description: $entry['description'],
            );
        }

        return $saved;
    }
}
