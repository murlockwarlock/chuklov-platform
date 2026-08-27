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
use App\Modules\Organizations\Domain\ValueObjects\IanaTimezone;
use App\Modules\Scheduling\Application\EnsureSpecialistIntervalAvailable;
use App\Modules\Scheduling\Domain\Models\UnavailablePeriod;
use App\Modules\Security\Application\RecordAuditEvent;
use App\Modules\Specialists\Domain\Models\Specialist;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class RescheduleB2bSalesCall
{
    public function __construct(
        private readonly OrganizationContext $context,
        private readonly OrganizationAuthorizer $authorizer,
        private readonly B2bProviderMutationGuard $providerMutationGuard,
        private readonly EnsureSpecialistIntervalAvailable $availability,
        private readonly RecordB2bProviderSyncEvent $providerEvents,
        private readonly RecordAuditEvent $audit,
    ) {}

    public function handle(
        User $actor,
        B2bSalesCall $salesCall,
        DateTimeInterface $newStartsAt,
        ?string $requestedTimezone = null,
        ?int $expectedEventVersion = null,
    ): B2bSalesCall {
        $organization = $this->context->organization();
        $this->authorizer->authorize($actor, $organization, OrganizationPermission::ManageB2bLeads);

        if ((int) $salesCall->organization_id !== $organization->getKey()) {
            throw new AuthorizationException('The sales call is outside the current organization.');
        }

        $requestedTimezone = $requestedTimezone === null
            ? null
            : $this->timezone($requestedTimezone);
        $newStartsAt = CarbonImmutable::instance($newStartsAt)->utc();

        if ($newStartsAt->second !== 0 || $newStartsAt->microsecond !== 0) {
            throw ValidationException::withMessages(['starts_at' => 'The sales-call time must use whole minutes.']);
        }

        try {
            $providerChangeBlocked = false;
            $result = DB::transaction(function () use ($actor, $salesCall, $newStartsAt, $requestedTimezone, $expectedEventVersion, $organization, &$providerChangeBlocked): B2bSalesCall {
                $locked = B2bSalesCall::query()
                    ->where('organization_id', $organization->getKey())
                    ->whereKey($salesCall->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($expectedEventVersion === null || (int) $locked->event_version !== $expectedEventVersion) {
                    throw ValidationException::withMessages([
                        'expected_event_version' => 'This sales call changed before the reschedule was applied. Refresh and try again.',
                    ]);
                }

                if ($locked->status !== B2bSalesCallStatus::Scheduled) {
                    throw ValidationException::withMessages(['sales_call' => 'A cancelled sales call cannot be rescheduled.']);
                }
                if (! $this->providerMutationGuard->allowGenerationChange($locked, $actor)) {
                    $providerChangeBlocked = true;

                    return $locked->refresh();
                }

                $specialist = Specialist::query()
                    ->where('organization_id', $organization->getKey())
                    ->whereKey($locked->specialist_id)
                    ->lockForUpdate()
                    ->firstOrFail();
                $occupancy = UnavailablePeriod::query()
                    ->where('organization_id', $organization->getKey())
                    ->where('b2b_sales_call_id', $locked->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();
                $durationMinutes = (int) round($locked->startsAtUtc()->diffInMinutes($locked->endsAtUtc()));
                if ($durationMinutes < 1) {
                    throw ValidationException::withMessages([
                        'sales_call' => 'The stored sales-call interval is invalid and cannot be rescheduled.',
                    ]);
                }
                $newEndsAt = $newStartsAt->addMinutes($durationMinutes);
                $scheduleTimezone = $this->availability->handle(
                    specialist: $specialist,
                    startsAt: $newStartsAt,
                    endsAt: $newEndsAt,
                    ignoreUnavailablePeriodId: (int) $occupancy->getKey(),
                );
                $hasProviderIdentity = $locked->providerIdentity() !== null;
                $providerOperation = null;
                $providerSyncStatus = VideoMeetingSyncStatus::NotRequired;
                if ($locked->meeting_mode === VideoMeetingMode::Automatic) {
                    $providerOperation = $hasProviderIdentity && $locked->provider_join_url !== null
                        ? VideoMeetingOperation::Update
                        : ($hasProviderIdentity ? VideoMeetingOperation::Recreate : VideoMeetingOperation::Create);
                    $providerSyncStatus = $locked->provider_sync_status === VideoMeetingSyncStatus::ReconciliationRequired
                        ? VideoMeetingSyncStatus::ReconciliationRequired
                        : VideoMeetingSyncStatus::Pending;
                } elseif ($hasProviderIdentity) {
                    $providerOperation = VideoMeetingOperation::Cancel;
                    $providerSyncStatus = VideoMeetingSyncStatus::CancellationPending;
                }
                $providerCorrelationKey = $locked->provider_correlation_key;
                if ($providerOperation === VideoMeetingOperation::Recreate
                    && $locked->provider_sync_status !== VideoMeetingSyncStatus::ReconciliationRequired) {
                    $providerCorrelationKey = bin2hex(random_bytes(16));
                }
                $locked->forceFill([
                    'starts_at' => $newStartsAt,
                    'ends_at' => $newEndsAt,
                    'schedule_timezone' => $scheduleTimezone,
                    'requested_timezone' => $requestedTimezone ?? $locked->requested_timezone,
                    'provider_sync_status' => $providerSyncStatus,
                    'provider_operation' => $providerOperation,
                    'provider_recreate_meeting_id' => $providerOperation === VideoMeetingOperation::Recreate
                        ? $locked->provider_meeting_id
                        : null,
                    'provider_correlation_key' => $providerCorrelationKey,
                    'provider_join_url' => null,
                    'provider_lease_token' => null,
                    'provider_lease_expires_at' => null,
                    'provider_lease_event_id' => null,
                    'provider_lease_processing_token' => null,
                    'provider_sync_version' => (int) $locked->provider_sync_version + 1,
                    'event_version' => (int) $locked->event_version + 1,
                    'provider_error_code' => null,
                ])->save();
                $occupancy->forceFill([
                    'starts_at' => $newStartsAt,
                    'ends_at' => $newEndsAt,
                ])->save();

                if ($providerOperation instanceof VideoMeetingOperation) {
                    $this->providerEvents->handle($organization, $locked, $providerOperation);
                }

                $this->audit->handle(
                    organization: $organization,
                    actor: $actor,
                    action: 'b2b.sales_call.rescheduled',
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

            if ($providerChangeBlocked) {
                throw ValidationException::withMessages(['provider' => B2bProviderMutationGuard::LOST_MESSAGE]);
            }

            return $result;
        } catch (QueryException $exception) {
            if ($this->isScheduleConflict($exception)) {
                throw ValidationException::withMessages([
                    'starts_at' => 'The selected sales-call time is no longer available.',
                ]);
            }

            throw $exception;
        }
    }

    private function timezone(string $timezone): string
    {
        try {
            return IanaTimezone::from($timezone)->value;
        } catch (\InvalidArgumentException) {
            throw ValidationException::withMessages(['timezone' => 'The requested timezone must be an IANA timezone.']);
        }
    }

    private function isScheduleConflict(QueryException $exception): bool
    {
        $sqlState = $exception->getCode() ?: ($exception->errorInfo[0] ?? null);

        return in_array($sqlState, ['23P01', '40P01'], true);
    }
}
