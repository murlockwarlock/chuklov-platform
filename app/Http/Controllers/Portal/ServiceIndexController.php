<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Modules\ClientPortal\Application\ProjectPortalService;
use App\Modules\Services\Application\ListPublishedServices;
use App\Modules\Services\Domain\Enums\CatalogItemType;
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
                ->map(function ($service) use ($serviceProjection): array {
                    $projection = $serviceProjection->handle($service, app()->getLocale());

                    return [
                        ...$projection,
                        'purchaseUrl' => in_array($service->catalogItemType(), [
                            CatalogItemType::OnlineProduct,
                            CatalogItemType::PhysicalProduct,
                        ], true)
                            ? route('portal.services.purchase', $service->getKey())
                            : null,
                    ];
                })
                ->values()
                ->all(),
            'urls' => [
                'home' => route('portal.home'),
                'booking' => route('portal.bookings.create'),
            ],
        ]);
    }
}
