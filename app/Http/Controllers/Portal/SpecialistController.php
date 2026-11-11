<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Modules\ClientPortal\Application\ProjectPortalSpecialist;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Scheduling\Application\ListBookableServices;
use App\Modules\Scheduling\Domain\Models\SpecialistServiceAssignment;
use App\Modules\Specialists\Domain\Models\Specialist;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SpecialistController extends Controller
{
    public function show(
        Request $request,
        OrganizationContext $organizationContext,
        ListBookableServices $bookableServices,
        ProjectPortalSpecialist $projection,
        int $specialist,
    ): Response {
        $record = Specialist::query()
            ->where('organization_id', $organizationContext->id())
            ->where('is_active', true)
            ->whereKey($specialist)
            ->firstOrFail();
        $services = $bookableServices->handle();
        $requestedServiceId = $request->integer('service_id');
        $selectedServiceId = $requestedServiceId > 0
            && $services->contains(fn ($service): bool => (int) $service->getKey() === $requestedServiceId)
            && SpecialistServiceAssignment::query()
                ->where('organization_id', $organizationContext->id())
                ->where('specialist_id', $record->getKey())
                ->where('service_id', $requestedServiceId)
                ->exists()
            ? $requestedServiceId
            : null;
        $profile = $projection->full($record, app()->getLocale(), $selectedServiceId);
        $contextService = $selectedServiceId === null
            ? null
            : $services->first(fn ($service): bool => (int) $service->getKey() === $selectedServiceId);

        return Inertia::render('Portal/SpecialistShow', [
            'specialist' => $profile,
            'context' => $contextService === null ? null : [
                'serviceId' => $contextService->getKey(),
                'serviceName' => $contextService->name,
            ],
            'urls' => [
                'back' => $selectedServiceId === null
                    ? route('portal.bookings.create')
                    : route('portal.bookings.create', ['service_id' => $selectedServiceId]),
                'services' => route('portal.services.index'),
            ],
        ]);
    }
}
