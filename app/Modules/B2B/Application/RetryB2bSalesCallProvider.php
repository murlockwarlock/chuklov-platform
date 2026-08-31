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

final class RetryB2bSalesCallProvider
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
                    'expected_event_version' => 'This sales call changed before provider retry was applied. Refresh and try again.',
                ]);
            }
            if (! $this->providerMutationGuard->allowGenerationChange($locked, $actor)) {
                $providerChangeBlocked = true;

                return $locked->refresh();
            }
            if ($locked->hasIncompleteProviderRecreatePair()) {
                throw ValidationException::withMessages([
                    'provider' => 'The provider recreation state is incomplete and must be reconciled before retrying.',
                ]);
            }
            $recreatePair = $locked->providerRecreatePair();
            $hasProviderIdentity = $locked->providerIdentity() !== null
                || $recreatePair !== null;
            if ($locked->meeting_mode !== VideoMeetingMode::Automatic
                && ! $hasProviderIdentity) {
                throw ValidationException::withMessages(['provider' => 'Automatic provider sync is disabled for manual-link calls.']);
            }
            if ($locked->status === B2bSalesCallStatus::Cancelled
                || $locked->meeting_mode === VideoMeetingMode::Manual) {
                $operation = VideoMeetingOperation::Cancel;
            } elseif ($locked->provider_sync_status === VideoMeetingSyncStatus::ReconciliationRequired
                && $locked->provider_operation instanceof VideoMeetingOperation) {
                $operation = $locked->provider_operation;
            } elseif ($locked->provider_operation === VideoMeetingOperation::Recreate
                || $recreatePair !== null) {
                $operation = VideoMeetingOperation::Recreate;
            } else {
                $operation = $locked->providerIdentity() === null
                    ? VideoMeetingOperation::Create
                    : VideoMeetingOperation::Update;
            }
            $recreateMeetingId = $recreatePair['meeting_id'] ?? $locked->provider_meeting_id;
            $recreateCorrelationKey = $recreatePair['correlation_key'] ?? $locked->provider_correlation_key;
            if ($operation === VideoMeetingOperation::Recreate
                && ((! is_string($recreateMeetingId) || trim($recreateMeetingId) === '')
                    || (! is_string($recreateCorrelationKey) || trim($recreateCorrelationKey) === '')
                    || (! is_string($locked->provider_correlation_key)
                        || trim($locked->provider_correlation_key) === ''))) {
                throw ValidationException::withMessages([
                    'provider' => 'The current Zoom generation must be reconciled before retrying.',
                ]);
            }
            $locked->forceFill([
                'provider_sync_status' => $locked->provider_sync_status === VideoMeetingSyncStatus::ReconciliationRequired
                    ? VideoMeetingSyncStatus::ReconciliationRequired
                    : ($operation === VideoMeetingOperation::Cancel
                        ? VideoMeetingSyncStatus::CancellationPending
                        : VideoMeetingSyncStatus::Pending),
                'provider_operation' => $operation,
                'provider_recreate_meeting_id' => match ($operation) {
                    VideoMeetingOperation::Recreate => $recreateMeetingId,
                    VideoMeetingOperation::Cancel => $recreatePair['meeting_id'] ?? null,
                    default => null,
                },
                'provider_recreate_correlation_key' => match ($operation) {
                    VideoMeetingOperation::Recreate => $recreateCorrelationKey,
                    VideoMeetingOperation::Cancel => $recreatePair['correlation_key'] ?? null,
                    default => null,
                },
                'provider_sync_version' => (int) $locked->provider_sync_version + 1,
                'event_version' => (int) $locked->event_version + 1,
                'provider_error_code' => null,
                'provider_lease_token' => null,
                'provider_lease_expires_at' => null,
                'provider_lease_event_id' => null,
                'provider_lease_processing_token' => null,
            ])->save();
            $this->providerEvents->handle($organization, $locked, $operation);
            $this->audit->handle(
                organization: $organization,
                actor: $actor,
                action: 'b2b.sales_call.provider_sync.updated',
                targetType: B2bSalesCall::class,
                targetId: (string) $locked->getKey(),
                metadata: [
                    'operation' => $operation->value,
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
