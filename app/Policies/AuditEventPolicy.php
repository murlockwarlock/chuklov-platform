<?php

namespace App\Policies;

use App\Models\User;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use App\Modules\Security\Domain\Models\AuditEvent;
use LogicException;

class AuditEventPolicy
{
    public function __construct(
        private readonly OrganizationAuthorizer $authorizer,
        private readonly OrganizationContext $context,
    ) {}

    public function viewAny(User $user): bool
    {
        try {
            return $this->authorizer->allows($user, $this->context->organization(), OrganizationPermission::ViewAuditEvents);
        } catch (LogicException) {
            return false;
        }
    }

    public function view(User $user, AuditEvent $event): bool
    {
        return $this->authorizer->allows($user, $event->organization, OrganizationPermission::ViewAuditEvents);
    }
}
