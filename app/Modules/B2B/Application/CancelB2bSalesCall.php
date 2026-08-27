<?php

namespace App\Modules\B2B\Application;

use App\Models\User;
use App\Modules\B2B\Domain\Enums\B2bSalesCallStatus;
use App\Modules\B2B\Domain\Enums\VideoMeetingMode;
use App\Modules\B2B\Domain\Enums\VideoMeetingOperation;
use App\Modules\B2B\Domain\Enums\VideoMeetingSyncStatus;
use App\Modules\B2B\Domain\Models\B2bSalesCall;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use App\Modules\Scheduling\Domain\Models\UnavailablePeriod;
use App\Modules\Security\Application\RecordAuditEvent;
use App\Modules\Specialists\Domain\Models\Specialist;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class CancelB2bSalesCall
{
    public function __construct(
        private readonly OrganizationContext $context,
        private readonly OrganizationAuthorizer $authorizer,
        private readonly RecordB2bProviderSyncEvent $providerEvents,
        private readonly RecordAuditEvent $audit,
    ) {}

    public function handle(User $actor, B2bSalesCall $salesCall, ?int $expectedEventVersion = null): B2bSalesCall
    {
        $organization = $this->context->organization();
        $this->authorizer->authorize($actor, $organization, OrganizationPermission::ManageB2bLeads);

        if ((int) $salesCall->organization_id !== $organization->getKey()) {
            throw new AuthorizationException('The sales call is outside the current organization.');
        }

        return DB::transaction(function () use ($actor, $salesCall, $expectedEventVersion, $organization): B2bSalesCall {
            $locked = B2bSalesCall::query()
                ->where('organization_id', $organization->getKey())
                ->whereKey($salesCall->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            if ($expectedEventVersion === null || (int) $locked->event_version !== $expectedEventVersion) {
                throw ValidationException::withMessages([
                    'expected_event_version' => 'This sales call changed before cancellation was applied. Refresh and try again.',
                ]);
            }
            if ($locked->status !== B2bSalesCallStatus::Scheduled) {
                return $locked->refresh();
            }

            Specialist::query()
                ->where('organization_id', $organization->getKey())
                ->whereKey($locked->specialist_id)
                ->lockForUpdate()
                ->firstOrFail();
            UnavailablePeriod::query()
                ->where('organization_id', $organization->getKey())
                ->where('b2b_sales_call_id', $locked->getKey())
                ->lockForUpdate()
                ->first()
                ?->delete();
            $hasProviderIdentity = $locked->providerIdentity() !== null;
            $requiresProviderCancellation = $locked->meeting_mode === VideoMeetingMode::Automatic
                && ($hasProviderIdentity
                    || $locked->provider_operation !== null
                    || $locked->provider_sync_status !== VideoMeetingSyncStatus::NotRequired);
            $providerSyncStatus = $requiresProviderCancellation
                ? ($locked->provider_sync_status === VideoMeetingSyncStatus::ReconciliationRequired
                    ? VideoMeetingSyncStatus::ReconciliationRequired
                    : VideoMeetingSyncStatus::CancellationPending)
                : VideoMeetingSyncStatus::NotRequired;
            $locked->forceFill([
                'status' => B2bSalesCallStatus::Cancelled,
                'cancelled_at' => now(),
                'provider_sync_status' => $providerSyncStatus,
                'provider_operation' => $requiresProviderCancellation ? VideoMeetingOperation::Cancel : null,
                'provider_sync_version' => (int) $locked->provider_sync_version + 1,
                'event_version' => (int) $locked->event_version + 1,
                'provider_error_code' => null,
                'provider_join_url' => null,
                'provider_lease_token' => null,
                'provider_lease_expires_at' => null,
                'provider_lease_event_id' => null,
                'provider_lease_processing_token' => null,
            ])->save();

            if ($requiresProviderCancellation) {
                $this->providerEvents->handle($organization, $locked, VideoMeetingOperation::Cancel);
            }

            $this->audit->handle(
                organization: $organization,
                actor: $actor,
                action: 'b2b.sales_call.cancelled',
                targetType: B2bSalesCall::class,
                targetId: (string) $locked->getKey(),
                metadata: [
                    'source' => 'crm',
                    'status' => $locked->status->value,
                    'provider_sync_status' => $locked->provider_sync_status->value,
                ],
            );

            return $locked->refresh();
        });
    }
}
