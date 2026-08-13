<?php

use App\Modules\Scheduling\Application\PruneBookingIdempotencyKeys;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('bookings:prune-idempotency', function (PruneBookingIdempotencyKeys $pruner): void {
    $this->info('Pruned '.$pruner->handle().' expired booking idempotency key(s).');
})->purpose('Remove expired booking creation idempotency records.');

Schedule::command('horizon:snapshot')->everyFiveMinutes();
Schedule::command('bookings:prune-idempotency')->daily();
