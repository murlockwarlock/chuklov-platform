<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Modules\ClientPortal\Application\ClientPortalContext;
use App\Modules\Surveys\Application\ListClientSurveys;
use App\Modules\Tracker\Application\ListClientTrackerOverview;
use Inertia\Inertia;
use Inertia\Response;

final class HealthController extends Controller
{
    public function __invoke(
        ClientPortalContext $context,
        ListClientTrackerOverview $tracker,
        ListClientSurveys $surveys,
    ): Response {
        return Inertia::render('Portal/Health', [
            'tracker' => $tracker->handle(),
            'surveys' => $surveys->handle($context->client()),
            'urls' => [
                'tracker' => route('portal.tracker'),
                'surveys' => route('portal.surveys.index'),
            ],
        ]);
    }
}
