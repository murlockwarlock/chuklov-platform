<?php

namespace App\Http\Middleware;

use App\Modules\ClientPortal\Application\ClientPortalContext;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Application\OrganizationContext;
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

        return $next($request);
    }
}
