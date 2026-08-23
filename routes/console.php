<?php

use App\Modules\AI\Application\Actions\ReclaimExpiredAiRuns;
use App\Modules\Conversations\Application\AdoptLegacyCompanionConversations;
use App\Modules\Scenarios\Application\ScheduleScenarioWork;
use App\Modules\Scheduling\Application\PruneBookingIdempotencyKeys;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('bookings:prune-idempotency', function (PruneBookingIdempotencyKeys $pruner): void {
    $this->info('Pruned '.$pruner->handle().' expired booking idempotency key(s).');
})->purpose('Remove expired booking creation idempotency records.');

Schedule::command('horizon:snapshot')->everyFiveMinutes();
Schedule::command('bookings:prune-idempotency')->daily();

Artisan::command('ai:runs-reclaim', function (ReclaimExpiredAiRuns $reclaimer): void {
    $result = $reclaimer->handle();

    $this->info("Reclaimed {$result['reclaimed']} expired AI run(s) and dispatched {$result['dispatched']} identifier-only job(s).");
})->purpose('Reclaim expired AI run leases and safely requeue stranded work.');

Schedule::command('ai:runs-reclaim')->everyMinute()->withoutOverlapping()->onOneServer();

Artisan::command('companion:adopt-legacy', function (AdoptLegacyCompanionConversations $adopter): void {
    $result = $adopter->handle();

    $this->info("Adopted {$result['adopted']} legacy conversation(s); skipped {$result['skipped']}; ambiguous {$result['ambiguous']}.");
})->purpose('Adopt only deterministic legacy M2 conversations into Client Companion.');

Artisan::command('scenarios:run', function (ScheduleScenarioWork $scheduler): void {
    $result = $scheduler->handle();

    $this->info('Dispatched '.$result['events'].' scenario event(s) and '.$result['actions'].' scenario action(s).');
})->purpose('Dispatch due scenario events and notification actions.');

Schedule::command('scenarios:run')->everyMinute()->withoutOverlapping();
