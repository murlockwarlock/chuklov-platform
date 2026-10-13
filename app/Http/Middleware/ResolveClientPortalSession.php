<?php

namespace App\Http\Middleware;

use App\Modules\ClientPortal\Application\ClientPortalContext;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Support\SupportedLocale;
use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

class ResolveClientPortalSession
{
    public function __construct(
        private readonly OrganizationContext $organizationContext,
        private readonly ClientPortalContext $clientContext,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $this->clientContext->clear();
        $clientId = $request->session()->get('client_portal.client_id');
        $isInteger = is_int($clientId) || (is_string($clientId) && ctype_digit($clientId));
        $client = null;

        if ($isInteger) {
            $client = Client::query()
                ->where('organization_id', $this->organizationContext->id())
                ->find((int) $clientId);

            if ($client instanceof Client) {
                $this->clientContext->set($client);
            } else {
                $request->session()->forget('client_portal.client_id');
            }
        }

        app()->setLocale($this->resolveLocale($request, $client));

        Inertia::encryptHistory();
        $historySubject = $this->organizationContext->id().':'.($client?->getKey() ?? 'guest');

        if ($request->session()->get('client_portal.history_subject') !== $historySubject) {
            $request->session()->forget([
                'portal_booking_result', 'profile_saved', 'medical_profile_saved', 'consents_saved',
                'gift_certificate_transfer_url', 'payout_feedback', 'telegram_link_url', 'telegram_link_error',
            ]);
            Inertia::clearHistory();
            $request->session()->put('client_portal.history_subject', $historySubject);
        }

        $response = $next($request);
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }

    private function resolveLocale(Request $request, ?Client $client): string
    {
        $language = $client->language ?? $request->session()->get('portal.locale');

        return SupportedLocale::normalize(
            is_string($language) ? $language : null,
            (string) config('portal.default_locale', 'ru'),
        );
    }
}
