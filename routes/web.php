<?php

use App\Http\Controllers\HealthController;
use App\Http\Controllers\Portal\ServiceIndexController;
use App\Http\Middleware\ResolveOrganization;
use Illuminate\Support\Facades\Route;

Route::get('/health', HealthController::class)->name('health');
Route::middleware(ResolveOrganization::class)->group(function (): void {
    Route::get('/', ServiceIndexController::class)->name('portal.services.index');
});
