<?php

use App\Modules\Scenarios\Application\ScheduleScenarioWork;
use App\Modules\Scheduling\Application\PruneBookingIdempotencyKeys;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('bookings:prune-idempotency', function (PruneBookingIdempotencyKeys $pruner): void {
    $this->info('Pruned '.$pruner->handle().' expired booking idempotency key(s).');
})->purpose('Remove expired booking creation idempotency records.');

Schedule::command('horizon:snapshot')->everyFiveMinutes();
Schedule::command('bookings:prune-idempotency')->daily();

Artisan::command('scenarios:run', function (ScheduleScenarioWork $scheduler): void {
    $result = $scheduler->handle();

    $this->info('Dispatched '.$result['events'].' scenario event(s) and '.$result['actions'].' scenario action(s).');
})->purpose('Dispatch due scenario events and notification actions.');

Schedule::command('scenarios:run')->everyMinute()->withoutOverlapping();
