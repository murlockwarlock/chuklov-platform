<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Organizations\Domain\Models\OrganizationMembership;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePrivilegedSessionIsCurrent
{
    private const SESSION_KEY = 'privileged_session_version';

    private const MEMBERSHIP_UPDATED_AT_KEY = 'privileged_membership_updated_at';

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return $next($request);
        }

        $currentVersion = User::query()
            ->whereKey($user->getAuthIdentifier())
            ->value('privileged_session_version');

        if (! is_numeric($currentVersion)) {
            return $this->invalidate($request);
        }

        $organizationId = config('tenancy.default_organization_id');
        $isInteger = is_int($organizationId)
            || (is_string($organizationId) && ctype_digit($organizationId));
        $organization = $isInteger ? Organization::query()->find((int) $organizationId) : null;
        $membership = $organization instanceof Organization
            ? OrganizationMembership::query()
                ->where('user_id', $user->getAuthIdentifier())
                ->where('organization_id', $organization->getKey())
                ->first()
            : null;

        if (! $membership instanceof OrganizationMembership
            || ! $membership->is_active
            || ! $membership->role->allows(OrganizationPermission::ViewAdmin)) {
            return $this->invalidate($request);
        }

        $userId = (string) $user->getAuthIdentifier();
        $sessionVersionKey = self::SESSION_KEY.'.'.$userId;
        $membershipUpdatedAtKey = self::MEMBERSHIP_UPDATED_AT_KEY.'.'.$userId;
        $sessionVersion = $request->session()->get($sessionVersionKey);

        if ($sessionVersion !== null && (int) $sessionVersion !== (int) $currentVersion) {
            return $this->invalidate($request);
        }

        $membershipUpdatedAt = $membership->updated_at === null
            ? null
            : (int) $membership->updated_at->getPreciseTimestamp(6);
        $sessionMembershipUpdatedAt = $request->session()->get($membershipUpdatedAtKey);

        if ($membershipUpdatedAt === null
            || ($sessionMembershipUpdatedAt !== null && (int) $sessionMembershipUpdatedAt !== $membershipUpdatedAt)) {
            return $this->invalidate($request);
        }

        $request->session()->put($sessionVersionKey, (int) $currentVersion);
        $request->session()->put($membershipUpdatedAtKey, $membershipUpdatedAt);

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
