<?php

use App\Modules\AI\Application\Actions\ReclaimExpiredAiRuns;
use App\Modules\B2B\Application\ScheduleB2bProviderSyncEvents;
use App\Modules\Broadcasts\Application\ScheduleBroadcastWork;
use App\Modules\Conversations\Application\AdoptLegacyCompanionConversations;
use App\Modules\Referrals\Application\ScheduleReferralIntegrationEvents;
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

Artisan::command('referrals:run', function (ScheduleReferralIntegrationEvents $scheduler): void {
    $this->info('Dispatched '.$scheduler->handle().' referral integration event(s).');
})->purpose('Dispatch pending referral integration events with crash-safe retries.');

Schedule::command('referrals:run')->everyMinute()->withoutOverlapping();

Artisan::command('b2b:provider-sync', function (ScheduleB2bProviderSyncEvents $scheduler): void {
    $this->info('Dispatched '.$scheduler->handle().' B2B provider event(s).');
})->purpose('Dispatch pending B2B video meeting provider events with crash-safe retries.');

Schedule::command('b2b:provider-sync')->everyMinute()->withoutOverlapping()->onOneServer();

Artisan::command('broadcasts:run', function (ScheduleBroadcastWork $scheduler): void {
    $result = $scheduler->handle();
    $this->info("Claimed {$result['campaigns']} campaign(s) and dispatched {$result['batches']} bounded batch job(s).");
})->purpose('Claim due broadcast campaigns and dispatch bounded recipient batches.');

Schedule::command('broadcasts:run')->everyMinute()->withoutOverlapping()->onOneServer();
