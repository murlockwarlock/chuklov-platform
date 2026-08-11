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
        $organizationId = $user instanceof User
            ? $user->organization_id
            : config('tenancy.default_organization_id');

        $isInteger = is_int($organizationId)
            || (is_string($organizationId) && ctype_digit($organizationId));

        abort_unless($isInteger, 503, 'Organization is not configured.');

        $this->context->set(Organization::query()->findOrFail((int) $organizationId));

        return $next($request);
    }
}
