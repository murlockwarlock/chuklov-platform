<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePrivilegedSessionIsCurrent
{
    private const SESSION_KEY = 'privileged_session_version';

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return $next($request);
        }

        $organizationId = config('tenancy.default_organization_id');
        $isOrganizationIdConfigured = is_int($organizationId)
            || (is_string($organizationId) && ctype_digit($organizationId));

        if ($isOrganizationIdConfigured && ! $user->memberships()
            ->active()
            ->where('organization_id', (int) $organizationId)
            ->exists()) {
            $this->invalidateSession($request);
            abort(403);
        }

        $currentVersion = User::query()
            ->whereKey($user->getAuthIdentifier())
            ->value('privileged_session_version');

        if (! is_numeric($currentVersion)) {
            return $this->invalidate($request);
        }

        $sessionVersion = $request->session()->get(self::SESSION_KEY);

        if ($sessionVersion !== null && (int) $sessionVersion !== (int) $currentVersion) {
            return $this->invalidate($request);
        }

        $request->session()->put(self::SESSION_KEY, (int) $currentVersion);

        return $next($request);
    }

    private function invalidate(Request $request): Response
    {
        $this->invalidateSession($request);

        return redirect()->guest(route('filament.admin.auth.login'));
    }

    private function invalidateSession(Request $request): void
    {
        auth('web')->logout();

        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }
    }
}
