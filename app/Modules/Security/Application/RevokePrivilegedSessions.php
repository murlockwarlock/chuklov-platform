<?php

namespace App\Modules\Security\Application;

use App\Models\User;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use Illuminate\Support\Facades\DB;

class RevokePrivilegedSessions
{
    public function __construct(
        private readonly OrganizationAuthorizer $authorizer,
        private readonly OrganizationContext $context,
        private readonly RecordAuditEvent $audit,
    ) {}

    public function handle(User $actor): void
    {
        $organization = $this->context->organization();
        $this->authorizer->authorize($actor, $organization, OrganizationPermission::ViewAdmin);

        DB::transaction(function () use ($actor, $organization): void {
            $lockedActor = User::query()
                ->whereKey($actor->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $lockedActor->increment('privileged_session_version');

            $this->audit->handle(
                $organization,
                $actor,
                'privileged.sessions.revoked',
                User::class,
                (string) $actor->getKey(),
            );
        });
    }
}
