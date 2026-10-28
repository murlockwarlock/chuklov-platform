<?php

namespace App\Modules\Sessions\Application;

use App\Modules\MedicalProfiles\Application\DTOs\MedicalPainData;
use App\Modules\MedicalProfiles\Domain\Contracts\MedicalEncryptorInterface;
use App\Modules\Sessions\Domain\Models\MedicalSession;
use App\Modules\Sessions\Domain\Models\MedicalSessionPain;

final readonly class MedicalSessionPainReader
{
    public function __construct(private MedicalEncryptorInterface $encryptor) {}

    /** @return list<MedicalPainData> */
    public function forSession(MedicalSession $session): array
    {
        $rows = $session->pains()->with('problemTags')->get();
        if ($rows->isEmpty()) {
            return [];
        }

        $previous = MedicalSession::query()
            ->where('organization_id', $session->organization_id)
            ->where('client_id', $session->client_id)
            ->where('id', '<>', $session->getKey())
            ->where(function ($query) use ($session): void {
                $query->where('occurred_at', '<', $session->occurred_at)
                    ->orWhere(function ($nested) use ($session): void {
                        $nested->where('occurred_at', $session->occurred_at)->where('id', '<', $session->getKey());
                    });
            })
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->first();
        $previousAfter = [];
        if ($previous instanceof MedicalSession) {
            $previousAfter = $previous->pains()->pluck('after_score', 'lineage_key')->map(static fn (mixed $value): ?int => $value === null ? null : (int) $value)->all();
        }

        return $rows->map(function (MedicalSessionPain $pain) use ($session, $previousAfter): MedicalPainData {
            return new MedicalPainData(
                id: (int) $pain->getKey(),
                lineageKey: (string) $pain->lineage_key,
                name: (string) $this->encryptor->decryptField(
                    (int) $session->organization_id,
                    $pain->getRawOriginal('name'),
                    (int) $pain->encryption_key_version,
                ),
                frequencyMode: (string) $pain->frequency_mode,
                frequencyCount: $pain->frequency_count !== null ? (int) $pain->frequency_count : null,
                frequencyPeriodUnit: $pain->frequency_period_unit !== null ? (string) $pain->frequency_period_unit : null,
                timeOfDay: is_array($pain->time_of_day) && $pain->time_of_day !== [] ? array_values($pain->time_of_day) : ['all'],
                beforeScore: $pain->before_score !== null ? (int) $pain->before_score : null,
                afterScore: $pain->after_score !== null ? (int) $pain->after_score : null,
                previousAfterScore: array_key_exists((string) $pain->lineage_key, $previousAfter) ? $previousAfter[(string) $pain->lineage_key] : null,
                tagIds: $pain->problemTags->modelKeys(),
            );
        })->all();
    }
}
