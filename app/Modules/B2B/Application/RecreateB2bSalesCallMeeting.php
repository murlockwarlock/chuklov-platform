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
use App\Modules\Security\Application\RecordAuditEvent;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class RecreateB2bSalesCallMeeting
{
    public function __construct(
        private readonly OrganizationContext $context,
        private readonly OrganizationAuthorizer $authorizer,
        private readonly B2bProviderMutationGuard $providerMutationGuard,
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

        $providerChangeBlocked = false;
        $result = DB::transaction(function () use ($actor, $salesCall, $expectedEventVersion, $organization, &$providerChangeBlocked): B2bSalesCall {
            $locked = B2bSalesCall::query()
                ->where('organization_id', $organization->getKey())
                ->whereKey($salesCall->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            if ($expectedEventVersion === null || (int) $locked->event_version !== $expectedEventVersion) {
                throw ValidationException::withMessages([
                    'expected_event_version' => 'This sales call changed before meeting recreation was applied. Refresh and try again.',
                ]);
            }
            if (! $this->providerMutationGuard->allowGenerationChange($locked, $actor)) {
                $providerChangeBlocked = true;

                return $locked->refresh();
            }
            if ($locked->status !== B2bSalesCallStatus::Scheduled || $locked->meeting_mode !== VideoMeetingMode::Automatic) {
                throw ValidationException::withMessages(['provider' => 'Only a scheduled automatic sales call can recreate a meeting.']);
            }
            if ($locked->provider_sync_status === VideoMeetingSyncStatus::ReconciliationRequired) {
                throw ValidationException::withMessages([
                    'provider' => 'The current Zoom generation must be reconciled before the meeting can be recreated.',
                ]);
            }
            $currentIdentity = $locked->providerIdentity();
            if ($currentIdentity === null
                || ! is_string($locked->provider_correlation_key)
                || trim($locked->provider_correlation_key) === '') {
                throw ValidationException::withMessages([
                    'provider' => 'The current Zoom generation must be reconciled before the meeting can be recreated.',
                ]);
            }
            $locked->forceFill([
                'provider_sync_status' => VideoMeetingSyncStatus::Pending,
                'provider_operation' => VideoMeetingOperation::Recreate,
                'provider_recreate_meeting_id' => $currentIdentity->meetingId,
                'provider_recreate_correlation_key' => $locked->provider_correlation_key,
                'provider_correlation_key' => bin2hex(random_bytes(16)),
                'provider_sync_version' => (int) $locked->provider_sync_version + 1,
                'event_version' => (int) $locked->event_version + 1,
                'provider_join_url' => null,
                'provider_error_code' => null,
                'provider_lease_token' => null,
                'provider_lease_expires_at' => null,
                'provider_lease_event_id' => null,
                'provider_lease_processing_token' => null,
            ])->save();
            $this->providerEvents->handle($organization, $locked, VideoMeetingOperation::Recreate);
            $this->audit->handle(
                organization: $organization,
                actor: $actor,
                action: 'b2b.sales_call.provider_sync.updated',
                targetType: B2bSalesCall::class,
                targetId: (string) $locked->getKey(),
                metadata: [
                    'operation' => VideoMeetingOperation::Recreate->value,
                    'status' => $locked->provider_sync_status->value,
                    'provider' => 'zoom',
                    'error_code' => null,
                ],
            );

            return $locked->refresh();
        });

        if ($providerChangeBlocked) {
            throw ValidationException::withMessages(['provider' => B2bProviderMutationGuard::LOST_MESSAGE]);
        }

        return $result;
    }
}
