<?php

namespace App\Http\Middleware;

use App\Modules\ClientPortal\Application\ClientPortalContext;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Support\SupportedLocale;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ResolveClientPortalSession
{
    public function __construct(
        private readonly OrganizationContext $organizationContext,
        private readonly ClientPortalContext $clientContext,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
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

        return $next($request);
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
