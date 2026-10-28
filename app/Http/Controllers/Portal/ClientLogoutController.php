<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Modules\ClientPortal\Application\ClientPortalContext;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Security\Application\RecordAuditEvent;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Throwable;

final class ClientLogoutController extends Controller
{
    public function __invoke(
        Request $request,
        ClientPortalContext $clientContext,
        OrganizationContext $organizationContext,
        RecordAuditEvent $audit,
    ): RedirectResponse {
        $client = $clientContext->client();
        $organization = $organizationContext->organization();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        try {
            $audit->handle(
                organization: $organization,
                actor: null,
                action: 'client.portal.logged_out',
                targetType: 'client',
                targetId: (string) $client->getKey(),
                metadata: [
                    'actor_type' => 'client',
                    'actor_client_id' => $client->getKey(),
                    'source' => 'portal',
                ],
            );
        } catch (Throwable $exception) {
            Log::error('client.portal.logout_audit_failed', [
                'operation' => 'client.portal.logout',
                'organization_id' => $organization->getKey(),
                'actor_type' => 'client',
                'actor_client_id' => $client->getKey(),
                'entity_type' => 'client',
                'entity_id' => $client->getKey(),
                'error_code' => 'audit_event_write_failed',
                'error_class' => $exception::class,
                'outcome' => 'failed',
                'occurred_at_utc' => now()->utc()->toIso8601String(),
            ]);
        }

        Inertia::clearHistory();

        return to_route('portal.home')->with('portal_logged_out', true);
    }
}
