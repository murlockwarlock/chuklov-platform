<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Http\Requests\RecordNpsSubmissionRequest;
use App\Modules\ClientPortal\Application\ClientPortalContext;
use App\Modules\Feedback\Application\GetFeedbackConfiguration;
use App\Modules\Feedback\Application\GetPortalFeedback;
use App\Modules\Feedback\Application\RecordNpsSubmission;
use App\Modules\Feedback\Domain\Enums\NpsBand;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use LogicException;
use Symfony\Component\HttpFoundation\RedirectResponse;

class FeedbackController extends Controller
{
    public function index(
        ClientPortalContext $context,
        GetPortalFeedback $feedback,
        Request $request,
    ): Response {
        try {
            $client = $context->client();
        } catch (LogicException) {
            abort(401);
        }

        return Inertia::render('Portal/Feedback', [
            'feedback' => $feedback->handle($client, app()->getLocale()),
            'result' => $request->session()->pull('feedback_result'),
        ]);
    }

    public function store(
        RecordNpsSubmissionRequest $request,
        ClientPortalContext $context,
        RecordNpsSubmission $record,
        GetFeedbackConfiguration $configuration,
    ): RedirectResponse {
        try {
            $client = $context->client();
        } catch (LogicException) {
            abort(401);
        }

        $validated = $request->validated();
        $submission = $record->handle(
            client: $client,
            score: (int) $validated['score'],
            internalFeedback: $validated['internal_feedback'] ?? null,
            idempotencyKey: (string) $validated['idempotency_key'],
        );
        $settings = $configuration->handle();
        $band = NpsBand::fromScore($submission->score, $settings['positiveThreshold']);
        $locale = app()->getLocale() === 'en' ? 'en' : 'ru';

        return to_route('portal.feedback')->with('feedback_result', [
            'band' => $band->value,
            'reviewLinks' => array_values(array_filter([
                $settings['reviewLinks'][$locale],
                $settings['reviewLinks'][$locale === 'ru' ? 'en' : 'ru'],
            ], static fn (?string $link): bool => $link !== null)),
        ]);
    }
}
