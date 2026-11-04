<?php

namespace App\Modules\Sessions\Application;

use App\Modules\Sessions\Domain\Models\MedicalCauseDefinition;
use App\Modules\Sessions\Domain\Models\MedicalSession;
use Illuminate\Validation\ValidationException;

final readonly class MedicalSessionCauseWriter
{
    /** @param array<int, mixed> $causeIds */
    public function replace(MedicalSession $session, array $causeIds, bool $manualOverride): void
    {
        $organizationId = (int) $session->organization_id;
        $existingCauseIds = $session->causes()->pluck('medical_cause_definitions.id')->map(static fn (mixed $id): int => (int) $id)->all();
        $normalizedIds = collect($causeIds)
            ->map(static fn (mixed $id): int => (int) $id)
            ->filter(static fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();

        if ($manualOverride && $normalizedIds !== []) {
            $causes = MedicalCauseDefinition::query()
                ->where('organization_id', $organizationId)
                ->whereIn('id', $normalizedIds)
                ->get(['id', 'is_active']);

            if ($causes->count() !== count($normalizedIds) || $causes->contains(
                static fn (MedicalCauseDefinition $cause): bool => ! $cause->is_active
                    && ! in_array((int) $cause->getKey(), $existingCauseIds, true),
            )) {
                throw ValidationException::withMessages(['cause_ids' => 'Одна из причин недоступна.']);
            }
        }

        $session->causes()->syncWithPivotValues(
            $manualOverride ? $normalizedIds : [],
            [
                'organization_id' => $organizationId,
                'client_id' => (int) $session->client_id,
            ],
        );
    }
}
