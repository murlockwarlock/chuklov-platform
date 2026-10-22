<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Modules\ClientPortal\Application\ClientPortalContext;
use App\Modules\Scheduling\Application\ResolveOnlineConsultationService;
use App\Modules\Surveys\Application\CompleteSurveyAttempt;
use App\Modules\Surveys\Application\GetClientSurveyAttempt;
use App\Modules\Surveys\Application\ListClientSurveys;
use App\Modules\Surveys\Application\ProjectSurveyContent;
use App\Modules\Surveys\Application\SaveSurveyAttempt;
use App\Modules\Surveys\Application\StartSurveyAttempt;
use App\Modules\Surveys\Domain\Enums\SurveyAttemptStatus;
use App\Modules\Surveys\Domain\Exceptions\SurveyAttemptUnavailableException;
use App\Modules\Surveys\Domain\Models\SurveyDefinition;
use App\Modules\Surveys\Domain\Models\SurveyReport;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class SurveyController extends Controller
{
    public function index(ClientPortalContext $context, ListClientSurveys $surveys): Response
    {
        $data = $surveys->handle($context->client());

        return Inertia::render('Portal/Surveys', [
            ...$data,
            'urls' => ['start' => route('portal.surveys.start', ['definitionId' => '__id__'])],
            'notices' => [
                'startUnavailable' => (bool) session('survey_start_unavailable', false),
                'resultUnavailable' => (bool) session('survey_result_unavailable', false),
            ],
        ]);
    }

    public function start(int $definitionId, ClientPortalContext $context, StartSurveyAttempt $start): RedirectResponse
    {
        $client = $context->client();
        $definition = SurveyDefinition::query()->where('organization_id', $client->organization_id)->findOrFail($definitionId);
        try {
            $attempt = $start->handle($client, $definition);
        } catch (SurveyAttemptUnavailableException) {
            return redirect()->route('portal.surveys.index')->with('survey_start_unavailable', true);
        }

        return redirect()->route('portal.surveys.show', $attempt->getKey());
    }

    public function show(int $attemptId, ClientPortalContext $context, GetClientSurveyAttempt $get, ProjectSurveyContent $content): Response|RedirectResponse
    {
        try {
            $attempt = $get->handle($context->client(), $attemptId);
        } catch (SurveyAttemptUnavailableException) {
            return $this->redirectToSurveysForUnavailableResult();
        }
        if ($attempt->status === SurveyAttemptStatus::Completed) {
            $reportId = $attempt->report()->value('id');

            return redirect()->route('portal.surveys.report', $reportId);
        }

        return Inertia::render('Portal/SurveyTake', [
            'attempt' => [
                'id' => $attempt->getKey(),
                'definition' => $content->definition($attempt->definition_snapshot, app()->getLocale()),
                'answers' => $attempt->answers_snapshot ?? [],
            ],
            'urls' => [
                'index' => route('portal.surveys.index'),
                'save' => route('portal.surveys.save', $attempt->getKey()),
                'complete' => route('portal.surveys.complete', $attempt->getKey()),
            ],
        ]);
    }

    public function save(int $attemptId, Request $request, ClientPortalContext $context, GetClientSurveyAttempt $get, SaveSurveyAttempt $save): RedirectResponse
    {
        try {
            $attempt = $get->handle($context->client(), $attemptId);
        } catch (SurveyAttemptUnavailableException) {
            return $this->redirectToSurveysForUnavailableResult();
        }
        $answers = $request->validate(['answers' => ['present', 'array']])['answers'];
        $save->handle($context->client(), $attempt, $answers);

        return back()->with('survey_saved', true);
    }

    public function complete(int $attemptId, Request $request, ClientPortalContext $context, GetClientSurveyAttempt $get, CompleteSurveyAttempt $complete): RedirectResponse
    {
        try {
            $attempt = $get->handle($context->client(), $attemptId);
        } catch (SurveyAttemptUnavailableException) {
            return $this->redirectToSurveysForUnavailableResult();
        }
        $answers = $request->validate(['answers' => ['present', 'array']])['answers'];
        $attempt = $complete->handle($context->client(), $attempt, $answers);

        return redirect()->route('portal.surveys.report', $attempt->report()->value('id'));
    }

    public function report(
        int $reportId,
        ClientPortalContext $context,
        ProjectSurveyContent $content,
        ResolveOnlineConsultationService $consultations,
        GetClientSurveyAttempt $get,
    ): Response|RedirectResponse {
        $client = $context->client();
        $report = SurveyReport::query()
            ->where('organization_id', $client->organization_id)
            ->where('client_id', $client->getKey())
            ->findOrFail($reportId);
        $attempt = $report->attempt()->firstOrFail();
        try {
            $get->handle($client, (int) $attempt->getKey());
        } catch (SurveyAttemptUnavailableException) {
            return $this->redirectToSurveysForUnavailableResult();
        }

        $snapshot = $content->report($report->report_snapshot, app()->getLocale());
        $consultation = $consultations->handle((int) $client->organization_id);

        return Inertia::render('Portal/SurveyReport', [
            'report' => [
                'title' => $snapshot['title'] ?: $report->title,
                'materializedAt' => $report->materialized_at->toIso8601String(),
                ...$snapshot,
            ],
            'urls' => [
                'index' => route('portal.surveys.index'),
                'companion' => route('portal.companion'),
                'repeat' => route('portal.surveys.start', ['definitionId' => $attempt->survey_definition_id]),
                'consultation' => $consultation === null
                    ? null
                    : route('portal.bookings.create', [
                        'service_id' => $consultation->getKey(),
                        'format' => 'online',
                    ]),
            ],
        ]);
    }

    private function redirectToSurveysForUnavailableResult(): RedirectResponse
    {
        return redirect()->route('portal.surveys.index')->with('survey_result_unavailable', true);
    }
}
