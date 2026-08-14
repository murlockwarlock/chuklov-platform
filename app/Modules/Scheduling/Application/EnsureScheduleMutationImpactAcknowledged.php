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
        $hasSuppliedAcknowledgement = $acknowledgeImpact || filled($acknowledgedImpactDigest);

        if (! $impact->hasConflicts() && ! $hasSuppliedAcknowledgement) {
            return;
        }

        if (! $impact->hasConflicts()) {
            throw ValidationException::withMessages([
                'schedule_impact' => ['Состав затронутых будущих записей изменился. Обновите предварительный просмотр.'],
                'schedule_impact_digest' => [''],
                'schedule_impact_bookings' => [json_encode([], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)],
            ]);
        }

        if (! $acknowledgeImpact || ! is_string($acknowledgedImpactDigest)
            || $acknowledgedImpactDigest === ''
            || ! hash_equals($impact->digest, $acknowledgedImpactDigest)) {
            throw ValidationException::withMessages([
                'schedule_impact' => ['Изменение затронет '.$impact->count().' '.($impact->count() === 1 ? 'будущую запись' : 'будущие записи').': '.$impact->summary().'. Проверьте список и подтвердите изменение.'],
                'schedule_impact_digest' => [$impact->digest],
                'schedule_impact_bookings' => [json_encode($impact->bookings, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)],
            ]);
        }
    }
}
