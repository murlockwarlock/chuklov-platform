<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Modules\ClientPortal\Application\ClientPortalContext;
use App\Modules\Surveys\Application\ListClientSurveys;
use App\Modules\Tracker\Application\ListClientTrackerOverview;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

final class HealthController extends Controller
{
    public function __invoke(
        ClientPortalContext $context,
        ListClientTrackerOverview $tracker,
        ListClientSurveys $surveys,
    ): Response|RedirectResponse {
        $surveyData = $surveys->handle($context->client());
        if ($surveyData['definitions'] === [] && $surveyData['attempts'] === []) {
            return to_route('portal.tracker');
        }

        return Inertia::render('Portal/Health', [
            'tracker' => $tracker->handle(),
            'surveys' => $surveyData,
            'urls' => [
                'tracker' => route('portal.tracker'),
                'surveys' => route('portal.surveys.index'),
            ],
        ]);
    }
}
