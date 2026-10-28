<?php

namespace App\Modules\MedicalProfiles\Application;

use App\Modules\MedicalProfiles\Application\DTOs\MedicalHistoryEntryData;
use App\Modules\MedicalProfiles\Domain\Contracts\MedicalEncryptorInterface;
use App\Modules\MedicalProfiles\Domain\Models\MedicalProfile;
use App\Modules\MedicalProfiles\Domain\Models\MedicalProfileHistoryEntry;

final readonly class MedicalProfileHistoryReader
{
    public function __construct(private MedicalEncryptorInterface $encryptor) {}

    /** @return list<MedicalHistoryEntryData> */
    public function forProfile(MedicalProfile $profile): array
    {
        $organizationId = (int) $profile->organization_id;
        $keyVersion = (int) $profile->encryption_key_version;

        return array_values($profile->historyEntries()->get()->map(fn (MedicalProfileHistoryEntry $entry): MedicalHistoryEntryData => new MedicalHistoryEntryData(
            id: (int) $entry->getKey(),
            lineageKey: (string) $entry->lineage_key,
            type: (string) $entry->entry_type,
            year: (int) $entry->year,
            description: (string) $this->encryptor->decryptField($organizationId, $entry->description, (int) $entry->encryption_key_version),
        ))->all());
    }
}
