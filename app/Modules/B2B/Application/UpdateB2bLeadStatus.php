<?php

namespace App\Modules\B2B\Application;

use App\Models\User;
use App\Modules\B2B\Domain\Enums\B2bLeadStatus;
use App\Modules\B2B\Domain\Models\B2bLead;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use App\Modules\Security\Application\RecordAuditEvent;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class UpdateB2bLeadStatus
{
    public function __construct(
        private readonly OrganizationContext $context,
        private readonly OrganizationAuthorizer $authorizer,
        private readonly RecordAuditEvent $audit,
    ) {}

    public function handle(
        User $actor,
        B2bLead $lead,
        B2bLeadStatus $status,
        ?int $expectedEventVersion = null,
    ): B2bLead {
        $organization = $this->context->organization();
        $this->authorizer->authorize($actor, $organization, OrganizationPermission::ManageB2bLeads);
        if ((int) $lead->organization_id !== $organization->getKey()) {
            throw new AuthorizationException('The B2B lead is outside the current organization.');
        }

        return DB::transaction(function () use ($actor, $lead, $status, $expectedEventVersion, $organization): B2bLead {
            $locked = B2bLead::query()
                ->where('organization_id', $organization->getKey())
                ->whereKey($lead->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            if ($expectedEventVersion === null || (int) $locked->event_version !== $expectedEventVersion) {
                throw ValidationException::withMessages([
                    'expected_event_version' => 'This B2B lead changed before its status was updated. Refresh and try again.',
                ]);
            }
            if ($locked->status === $status) {
                return $locked->refresh();
            }
            if (! $this->isAllowed($locked->status, $status)) {
                throw ValidationException::withMessages(['status' => 'This B2B lead status transition is not allowed.']);
            }

            $oldStatus = $locked->status;
            $locked->forceFill([
                'status' => $status,
                'event_version' => (int) $locked->event_version + 1,
            ])->save();
            $this->audit->handle(
                organization: $organization,
                actor: $actor,
                action: 'b2b.lead.status.updated',
                targetType: B2bLead::class,
                targetId: (string) $locked->getKey(),
                metadata: [
                    'source' => 'crm',
                    'old_status' => $oldStatus->value,
                    'new_status' => $status->value,
                ],
            );

            return $locked->refresh();
        });
    }

    private function isAllowed(B2bLeadStatus $from, B2bLeadStatus $to): bool
    {
        return match ($from) {
            B2bLeadStatus::New => in_array($to, [B2bLeadStatus::Contacted, B2bLeadStatus::ZoomScheduled, B2bLeadStatus::Closed], true),
            B2bLeadStatus::Contacted => in_array($to, [B2bLeadStatus::ZoomScheduled, B2bLeadStatus::Closed], true),
            B2bLeadStatus::ZoomScheduled => in_array($to, [B2bLeadStatus::Contacted, B2bLeadStatus::Closed], true),
            B2bLeadStatus::Closed => false,
        };
    }
}
