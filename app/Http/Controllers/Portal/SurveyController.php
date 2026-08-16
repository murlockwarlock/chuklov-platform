<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Modules\ClientPortal\Application\ClientPortalContext;
use App\Modules\Surveys\Application\CompleteSurveyAttempt;
use App\Modules\Surveys\Application\GetClientSurveyAttempt;
use App\Modules\Surveys\Application\ListClientSurveys;
use App\Modules\Surveys\Application\ProjectSurveyContent;
use App\Modules\Surveys\Application\SaveSurveyAttempt;
use App\Modules\Surveys\Application\StartSurveyAttempt;
use App\Modules\Surveys\Domain\Enums\SurveyAttemptStatus;
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
        return Inertia::render('Portal/Surveys', [
            ...$surveys->handle($context->client()),
            'urls' => ['start' => route('portal.surveys.start', ['definitionId' => '__id__'])],
        ]);
    }

    public function start(int $definitionId, ClientPortalContext $context, StartSurveyAttempt $start): RedirectResponse
    {
        $client = $context->client();
        $definition = SurveyDefinition::query()->where('organization_id', $client->organization_id)->findOrFail($definitionId);
        $attempt = $start->handle($client, $definition);

        return redirect()->route('portal.surveys.show', $attempt->getKey());
    }

    public function show(int $attemptId, ClientPortalContext $context, GetClientSurveyAttempt $get, ProjectSurveyContent $content): Response|RedirectResponse
    {
        $attempt = $get->handle($context->client(), $attemptId);
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
        $answers = $request->validate(['answers' => ['present', 'array']])['answers'];
        $save->handle($context->client(), $get->handle($context->client(), $attemptId), $answers);

        return back()->with('survey_saved', true);
    }

    public function complete(int $attemptId, Request $request, ClientPortalContext $context, GetClientSurveyAttempt $get, CompleteSurveyAttempt $complete): RedirectResponse
    {
        $answers = $request->validate(['answers' => ['present', 'array']])['answers'];
        $attempt = $complete->handle($context->client(), $get->handle($context->client(), $attemptId), $answers);

        return redirect()->route('portal.surveys.report', $attempt->report()->value('id'));
    }

    public function report(int $reportId, ClientPortalContext $context, ProjectSurveyContent $content): Response
    {
        $client = $context->client();
        $report = SurveyReport::query()
            ->where('organization_id', $client->organization_id)
            ->where('client_id', $client->getKey())
            ->findOrFail($reportId);

        $snapshot = $content->report($report->report_snapshot, app()->getLocale());

        return Inertia::render('Portal/SurveyReport', [
            'report' => [
                'title' => $snapshot['title'] ?: $report->title,
                'materializedAt' => $report->materialized_at->toIso8601String(),
                ...$snapshot,
            ],
            'urls' => ['index' => route('portal.surveys.index')],
        ]);
    }
}
