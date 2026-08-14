<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Modules\ClientPortal\Application\ProjectPortalService;
use App\Modules\Services\Application\ListPublishedServices;
use Inertia\Inertia;
use Inertia\Response;

class ServiceIndexController extends Controller
{
    public function __invoke(
        ListPublishedServices $services,
        ProjectPortalService $serviceProjection,
    ): Response {
        return Inertia::render('Services/Index', [
            'services' => $services->handle()
                ->map(fn ($service): array => $serviceProjection->handle($service, app()->getLocale()))
                ->values()
                ->all(),
            'urls' => [
                'home' => route('portal.home'),
                'booking' => route('portal.bookings.create'),
            ],
        ]);
    }
}
