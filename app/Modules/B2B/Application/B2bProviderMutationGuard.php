<?php

namespace App\Modules\B2B\Application;

use App\Models\User;
use App\Modules\B2B\Domain\Enums\VideoMeetingOperation;
use App\Modules\B2B\Domain\Enums\VideoMeetingSyncStatus;
use App\Modules\B2B\Domain\Models\B2bSalesCall;
use App\Modules\Security\Application\RecordAuditEvent;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use Throwable;

final class B2bProviderMutationGuard
{
    public const BLOCKED_MESSAGE = 'Provider synchronization is currently in progress; retry shortly.';

    public const LOST_MESSAGE = 'The previous provider synchronization expired; reconcile the current Zoom generation before retrying.';

    public function __construct(private readonly RecordAuditEvent $audit) {}

    public function allowGenerationChange(B2bSalesCall $salesCall, ?User $actor = null): bool
    {
        if (! $this->hasLease($salesCall)) {
            return true;
        }

        if ($this->leaseIsActive($salesCall)) {
            throw ValidationException::withMessages(['provider' => self::BLOCKED_MESSAGE]);
        }

        $this->markExpiredLeaseAsReconciliationRequired($salesCall, $actor);
        $salesCall->forceFill($this->clearLease())->save();

        return false;
    }

    public function markExpiredLeaseAsReconciliationRequired(B2bSalesCall $salesCall, ?User $actor = null): void
    {
        if (! $this->hasLease($salesCall) || $this->leaseIsActive($salesCall)) {
            return;
        }

        if ($salesCall->provider_sync_status === VideoMeetingSyncStatus::ReconciliationRequired
            && $salesCall->provider_error_code === 'provider_worker_lost') {
            return;
        }

        $salesCall->forceFill([
            'provider_sync_status' => VideoMeetingSyncStatus::ReconciliationRequired,
            'provider_error_code' => 'provider_worker_lost',
        ])->save();
        $this->audit->handle(
            organization: $salesCall->organization,
            actor: $actor,
            action: 'b2b.sales_call.provider_sync.updated',
            targetType: B2bSalesCall::class,
            targetId: (string) $salesCall->getKey(),
            metadata: [
                'operation' => $salesCall->provider_operation instanceof VideoMeetingOperation
                    ? $salesCall->provider_operation->value
                    : '',
                'status' => VideoMeetingSyncStatus::ReconciliationRequired->value,
                'provider' => (string) ($salesCall->provider_name ?? 'zoom'),
                'error_code' => 'provider_worker_lost',
            ],
        );
    }

    private function hasLease(B2bSalesCall $salesCall): bool
    {
        return $salesCall->provider_lease_token !== null
            || $salesCall->provider_lease_expires_at !== null
            || $salesCall->provider_lease_event_id !== null
            || $salesCall->provider_lease_processing_token !== null;
    }

    private function leaseIsActive(B2bSalesCall $salesCall): bool
    {
        if ($salesCall->provider_lease_expires_at === null) {
            return false;
        }

        try {
            return CarbonImmutable::parse((string) $salesCall->provider_lease_expires_at)
                ->greaterThan(CarbonImmutable::now('UTC'));
        } catch (Throwable) {
            return false;
        }
    }

    /** @return array{provider_lease_token: null, provider_lease_expires_at: null, provider_lease_event_id: null, provider_lease_processing_token: null} */
    private function clearLease(): array
    {
        return [
            'provider_lease_token' => null,
            'provider_lease_expires_at' => null,
            'provider_lease_event_id' => null,
            'provider_lease_processing_token' => null,
        ];
    }
}
