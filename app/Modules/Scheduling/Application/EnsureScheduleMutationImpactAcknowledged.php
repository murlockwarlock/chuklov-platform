<?php

namespace App\Modules\Scheduling\Application;

use Illuminate\Validation\ValidationException;

final class EnsureScheduleMutationImpactAcknowledged
{
    public function handle(
        ScheduleMutationImpact $impact,
        bool $acknowledgeImpact,
        ?string $acknowledgedImpactDigest = null,
    ): void {
        if (! $impact->hasConflicts()) {
            return;
        }

        if (! $acknowledgeImpact || ! is_string($acknowledgedImpactDigest)
            || $acknowledgedImpactDigest === ''
            || ! hash_equals($impact->digest, $acknowledgedImpactDigest)) {
            throw ValidationException::withMessages([
                'schedule_impact' => [$impact->count().' future booking(s) are affected: '.$impact->summary().'. Preview and acknowledge the current impact before saving.'],
                'schedule_impact_digest' => [$impact->digest],
                'schedule_impact_bookings' => [json_encode($impact->bookings, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)],
            ]);
        }
    }
}
