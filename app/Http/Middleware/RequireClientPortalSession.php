<?php

namespace App\Http\Middleware;

use App\Modules\ClientPortal\Application\ClientPortalContext;
use Closure;
use Illuminate\Http\Request;
use LogicException;
use Symfony\Component\HttpFoundation\Response;

class RequireClientPortalSession
{
    public function __construct(private readonly ClientPortalContext $clientContext) {}

    public function handle(Request $request, Closure $next): Response
    {
        try {
            $this->clientContext->client();
        } catch (LogicException) {
            if ($request->isMethod('GET') && $request->header('X-Inertia') === 'true') {
                return to_route('portal.home');
            }

            abort(401);
        }

        return $next($request);
    }
}
