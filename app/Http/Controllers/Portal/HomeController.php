<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Modules\Channels\Application\ResolveTelegramMiniAppEntry;
use App\Modules\ClientPortal\Application\ClientPortalContext;
use App\Modules\ClientPortal\Application\GetClientHomeHealthAction;
use App\Modules\Content\Application\GetClientEntryMedia;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Scheduling\Application\ListClientBookings;
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
        ResolveTelegramMiniAppEntry $telegramEntries,
        GetClientEntryMedia $entryMedia,
        GetClientHomeHealthAction $homeHealth,
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

        $upcoming = $bookings->nextForHome(app()->getLocale());
        Inertia::encryptHistory();
        $trackerData = $tracker->handle();
        $healthAction = $homeHealth->handle($client, $trackerData);

        return Inertia::render('Portal/Home', [
            'upcomingBooking' => $upcoming,
            'healthAction' => $healthAction,
            'stories' => fn (): array => array_values(array_filter($entryMedia->handle(app()->getLocale()), static fn (array $item): bool => $item['key'] !== 'telegram_welcome')),
        ]);
    }

    private function launchEntry(mixed $entry, ResolveTelegramMiniAppEntry $telegramEntries): ?string
    {
        return $telegramEntries->destinationOrNull($entry) === null || ! is_string($entry)
            ? null
            : $entry;
    }
}
