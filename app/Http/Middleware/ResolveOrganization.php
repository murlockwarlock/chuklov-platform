<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Models\Organization;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ResolveOrganization
{
    public function __construct(private readonly OrganizationContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $organizationId = config('tenancy.default_organization_id');

        $isInteger = is_int($organizationId)
            || (is_string($organizationId) && ctype_digit($organizationId));

        abort_unless($isInteger, 503, 'Organization is not configured.');

        $organization = Organization::query()->findOrFail((int) $organizationId);

        abort_if($user instanceof User && $user->membershipFor($organization) === null, 403);

        $this->context->set($organization);

        return $next($request);
    }
}
