<?php

namespace App\Http\Middleware;

use App\Modules\ClientPortal\Application\ClientPortalContext;
use App\Modules\Organizations\Application\OrganizationContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use LogicException;
use Symfony\Component\HttpFoundation\Response;

class RequireClientPortalSession
{
    public function __construct(
        private readonly ClientPortalContext $clientContext,
        private readonly OrganizationContext $organizationContext,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        try {
            $this->clientContext->client();
        } catch (LogicException) {
            if ($request->routeIs('portal.medical-attachments.download', 'portal.medical-attachments.upload')) {
                $isInertiaNavigation = $request->isMethod('GET') && $request->header('X-Inertia') === 'true';
                $responseStatus = $isInertiaNavigation ? 302 : Response::HTTP_UNAUTHORIZED;
                $uuid = $request->route('uuid');
                $targetId = is_string($uuid) && Str::isUuid($uuid) ? $uuid : null;

                Log::warning('portal.medical_attachment.access_denied', [
                    'operation' => $request->routeIs('portal.medical-attachments.download')
                        ? 'portal.medical-attachments.download'
                        : 'portal.medical-attachments.upload',
                    'organization_id' => $this->organizationContext->id(),
                    'actor_type' => 'client',
                    'actor_client_id' => null,
                    'entity_type' => $targetId === null ? null : 'medical_attachment',
                    'entity_id' => $targetId,
                    'safe_error_code' => 'client_session_required',
                    'outcome' => 'denied',
                    'response_status' => $responseStatus,
                    'occurred_at_utc' => now()->utc()->toIso8601String(),
                ]);

                if ($isInertiaNavigation) {
                    return to_route('portal.home');
                }

                $message = __('Войдите в личный кабинет и снова откройте медицинские данные.');

                return $request->expectsJson()
                    ? response()->json(['message' => $message], $responseStatus)
                    : response($message, $responseStatus)->header('Content-Type', 'text/plain; charset=UTF-8');
            }

            if ($request->isMethod('GET') && $request->header('X-Inertia') === 'true') {
                return to_route('portal.home');
            }

            abort(401);
        }

        return $next($request);
    }
}
