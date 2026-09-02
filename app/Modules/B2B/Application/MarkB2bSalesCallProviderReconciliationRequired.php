<?php

namespace App\Modules\B2B\Application;

use App\Models\User;
use App\Modules\B2B\Domain\Enums\B2bSalesCallStatus;
use App\Modules\B2B\Domain\Enums\VideoMeetingMode;
use App\Modules\B2B\Domain\Enums\VideoMeetingOperation;
use App\Modules\B2B\Domain\Enums\VideoMeetingSyncStatus;
use App\Modules\B2B\Domain\Models\B2bSalesCall;
use App\Modules\B2B\Domain\ValueObjects\ProviderAccountAffinity;
use App\Modules\B2B\Domain\ValueObjects\VideoMeetingIdentity;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use App\Modules\Security\Application\RecordAuditEvent;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class MarkB2bSalesCallProviderReconciliationRequired
{
    public function __construct(
        private readonly OrganizationContext $context,
        private readonly OrganizationAuthorizer $authorizer,
        private readonly B2bProviderMutationGuard $providerMutationGuard,
        private readonly RecordB2bProviderSyncEvent $providerEvents,
        private readonly RecordAuditEvent $audit,
    ) {}

    public function handle(
        User $actor,
        B2bSalesCall $salesCall,
        VideoMeetingIdentity $identity,
        string $errorCode,
        ?int $expectedEventVersion = null,
        ?int $expectedProviderSyncVersion = null,
    ): B2bSalesCall {
        $organization = $this->context->organization();
        $this->authorizer->authorize($actor, $organization, OrganizationPermission::ManageB2bLeads);

        if ((int) $salesCall->organization_id !== $organization->getKey()) {
            throw new AuthorizationException('The sales call is outside the current organization.');
        }

        $providerChangeBlocked = false;
        $result = DB::transaction(function () use ($actor, $salesCall, $identity, $errorCode, $expectedEventVersion, $expectedProviderSyncVersion, $organization, &$providerChangeBlocked): B2bSalesCall {
            $locked = B2bSalesCall::query()
                ->where('organization_id', $organization->getKey())
                ->whereKey($salesCall->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->status !== B2bSalesCallStatus::Scheduled
                || $locked->meeting_mode !== VideoMeetingMode::Automatic
                || ! $this->sameIdentity($locked->providerIdentity(), $identity)) {
                return $locked->refresh();
            }
            if (($expectedEventVersion !== null && (int) $locked->event_version !== $expectedEventVersion)
                || ($expectedProviderSyncVersion !== null && (int) $locked->provider_sync_version !== $expectedProviderSyncVersion)) {
                return $locked->refresh();
            }
            if (! $this->providerMutationGuard->allowGenerationChange($locked, $actor)) {
                $providerChangeBlocked = true;

                return $locked->refresh();
            }

            $locked->forceFill([
                'provider_sync_status' => VideoMeetingSyncStatus::ReconciliationRequired,
                'provider_operation' => VideoMeetingOperation::Reconcile,
                'provider_sync_version' => (int) $locked->provider_sync_version + 1,
                'event_version' => (int) $locked->event_version + 1,
                'provider_join_url' => null,
                'provider_error_code' => $errorCode,
                'provider_recreate_meeting_id' => null,
                'provider_recreate_correlation_key' => null,
                'provider_recreate_account_id' => null,
                'provider_recreate_host_user_id' => null,
                'provider_lease_token' => null,
                'provider_lease_expires_at' => null,
                'provider_lease_event_id' => null,
                'provider_lease_processing_token' => null,
            ])->save();
            $this->providerEvents->handle($organization, $locked, VideoMeetingOperation::Reconcile);
            $this->audit->handle(
                organization: $organization,
                actor: $actor,
                action: 'b2b.sales_call.provider_sync.updated',
                targetType: B2bSalesCall::class,
                targetId: (string) $locked->getKey(),
                metadata: [
                    'operation' => VideoMeetingOperation::Reconcile->value,
                    'status' => VideoMeetingSyncStatus::ReconciliationRequired->value,
                    'provider' => 'zoom',
                    'error_code' => $errorCode,
                ],
            );

            return $locked->refresh();
        });

        if ($providerChangeBlocked) {
            throw ValidationException::withMessages(['provider' => B2bProviderMutationGuard::LOST_MESSAGE]);
        }

        return $result;
    }

    private function sameIdentity(?VideoMeetingIdentity $current, VideoMeetingIdentity $expected): bool
    {
        return $current instanceof VideoMeetingIdentity
            && $current->meetingId === $expected->meetingId
            && $current->meetingUuid === $expected->meetingUuid
            && (($current->providerAccountAffinity === null && $expected->providerAccountAffinity === null)
                || ($current->providerAccountAffinity instanceof ProviderAccountAffinity
                    && $expected->providerAccountAffinity instanceof ProviderAccountAffinity
                    && $current->providerAccountAffinity->equals($expected->providerAccountAffinity)));
    }
}
