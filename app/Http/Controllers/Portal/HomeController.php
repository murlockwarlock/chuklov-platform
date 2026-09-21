<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Modules\Channels\Application\ResolveTelegramMiniAppEntry;
use App\Modules\ClientPortal\Application\ClientPortalContext;
use App\Modules\ClientPortal\Application\PortalClientMessages;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Scheduling\Application\ListClientBookings;
use App\Modules\Surveys\Application\ListClientSurveys;
use App\Modules\Tracker\Application\ListClientTrackerOverview;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use LogicException;

class HomeController extends Controller
{
    public function __invoke(
        Request $request,
        ClientPortalContext $clientContext,
        ListClientBookings $bookings,
        ListClientTrackerOverview $tracker,
        ListClientSurveys $surveys,
        ResolveTelegramMiniAppEntry $telegramEntries,
        PortalClientMessages $messages,
    ): Response {
        try {
            $client = $clientContext->client();
        } catch (LogicException) {
            $client = null;
        }

        if (! $client instanceof Client) {
            return Inertia::render('Portal/Entry', [
                'auth' => [
                    'telegramAuthUrl' => route('portal.telegram.auth'),
                    'telegramAuthError' => $request->session()->pull('telegram_auth_error'),
                    'telegramWebRequestUrl' => route('portal.telegram.web.request'),
                    'telegramWebStatusUrl' => route('portal.telegram.web.status'),
                    'telegramWebUrl' => $request->session()->get('telegram_web_auth.url'),
                    'emailRequestUrl' => route('portal.email.request'),
                    'emailVerifyUrl' => route('portal.email.verify'),
                    'emailCodeSent' => (bool) $request->session()->pull('email_code_sent', false),
                    'telegramLaunchEntry' => $this->launchEntry($request->query('telegram_entry'), $telegramEntries),
                ],
            ]);
        }

        $upcoming = $bookings->handle(app()->getLocale())['upcoming'];
        $trackerData = $tracker->handle();
        $surveyData = $surveys->handle($client);
        $healthAction = $this->healthAction($trackerData, $surveyData, $messages);

        return Inertia::render('Portal/Home', [
            'upcomingBooking' => $upcoming[0] ?? null,
            'healthAction' => $healthAction,
        ]);
    }

    /**
     * @param  array<string, mixed>  $tracker
     * @param  array<string, mixed>  $surveys
     * @return array{title: string, titleKey?: string, summary?: string, summaryKey?: string, url: string}|null
     */
    private function healthAction(array $tracker, array $surveys, PortalClientMessages $messages): ?array
    {
        $today = $tracker['today'][0] ?? null;
        if (is_array($today)) {
            return [
                'title' => (string) ($today['title'] ?? ''),
                'titleKey' => 'tracker.today',
                'summary' => (string) ($today['title'] ?? ''),
                'url' => route('portal.tracker'),
            ];
        }
        $definition = $surveys['definitions'][0] ?? null;
        if (is_array($definition)) {
            return [
                'title' => (string) ($definition['title'] ?? $messages->message('health_test_title')),
                'summaryKey' => 'home.newTest',
                'url' => route('portal.health'),
            ];
        }
        if (is_string($tracker['monthlyPractice'] ?? null) && trim($tracker['monthlyPractice']) !== '') {
            return [
                'title' => '',
                'titleKey' => 'tracker.program',
                'summaryKey' => 'home.monthlyPractice',
                'url' => route('portal.tracker'),
            ];
        }

        return null;
    }

    private function launchEntry(mixed $entry, ResolveTelegramMiniAppEntry $telegramEntries): ?string
    {
        return $telegramEntries->destinationOrNull($entry) === null || ! is_string($entry)
            ? null
            : $entry;
    }
}
