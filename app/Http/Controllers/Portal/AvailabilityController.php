<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Http\Requests\PortalAvailabilityRequest;
use App\Modules\ClientPortal\Application\ClientPortalContext;
use App\Modules\Scheduling\Application\CalculateAvailability;
use App\Modules\Scheduling\Domain\Enums\VisitFormat;
use Inertia\Inertia;
use Inertia\Response;

class AvailabilityController extends Controller
{
    public function __invoke(
        PortalAvailabilityRequest $request,
        CalculateAvailability $availability,
        ClientPortalContext $clientContext,
    ): Response {
        $validated = $request->validated();
        $format = VisitFormat::from($validated['format']);
        $result = $availability->forClient(
            client: $clientContext->client(),
            specialistId: (int) $validated['specialist_id'],
            serviceId: (int) $validated['service_id'],
            dateFrom: $validated['date_from'],
            dateTo: $validated['date_to'],
            format: $format,
            displayTimezone: $validated['display_timezone'] ?? null,
        );

        return Inertia::render('Portal/Availability', [
            'availability' => $result->toArray(),
            'query' => [
                'specialistId' => (int) $validated['specialist_id'],
                'serviceId' => (int) $validated['service_id'],
                'dateFrom' => $validated['date_from'],
                'dateTo' => $validated['date_to'],
                'format' => $format->value,
            ],
        ]);
    }
}
