<?php

namespace App\Modules\B2B\Application;

use App\Models\User;
use App\Modules\B2B\Domain\Enums\B2bLeadSource;
use App\Modules\B2B\Domain\Enums\B2bLeadStatus;
use App\Modules\B2B\Domain\Enums\B2bSalesCallStatus;
use App\Modules\B2B\Domain\Enums\VideoMeetingMode;
use App\Modules\B2B\Domain\Enums\VideoMeetingOperation;
use App\Modules\B2B\Domain\Enums\VideoMeetingSyncStatus;
use App\Modules\B2B\Domain\Models\B2bLead;
use App\Modules\B2B\Domain\Models\B2bSalesCall;
use App\Modules\Broadcasts\Application\GetClientB2bSpecialistAnswer;
use App\Modules\Broadcasts\Domain\Enums\B2bSpecialistAnswer;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use App\Modules\Organizations\Domain\ValueObjects\IanaTimezone;
use App\Modules\Scenarios\Application\RecordScenarioEvent;
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

final class SubmitB2bLead
{
    public function __construct(
        private readonly OrganizationContext $context,
        private readonly OrganizationAuthorizer $authorizer,
        private readonly EnsureSpecialistIntervalAvailable $availability,
        private readonly GetB2bSalesCallDuration $duration,
        private readonly GetClientB2bSpecialistAnswer $specialistAnswer,
        private readonly RecordScenarioEvent $scenarioEvents,
        private readonly RecordB2bProviderSyncEvent $providerEvents,
        private readonly RecordAuditEvent $audit,
    ) {}

    public function handle(
        User|Client $actor,
        Client $client,
        Specialist $specialist,
        DateTimeInterface $startsAt,
        string $requestedTimezone,
        string $idempotencyKey,
        B2bLeadSource $source,
        VideoMeetingMode $meetingMode = VideoMeetingMode::Automatic,
        ?string $manualMeetingUrl = null,
    ): B2bLead {
        $organization = $this->context->organization();

        if ((int) $client->organization_id !== $organization->getKey()) {
            throw new AuthorizationException('The client is outside the current organization.');
        }

        if ((int) $specialist->organization_id !== $organization->getKey()) {
            throw new AuthorizationException('The specialist is outside the current organization.');
        }

        if ($actor instanceof User) {
            $this->authorizer->authorize($actor, $organization, OrganizationPermission::ManageB2bLeads);
        } elseif ((int) $actor->getKey() !== (int) $client->getKey()) {
            throw new AuthorizationException('A client may only submit its own B2B lead.');
        }

        $requestedTimezone = $this->timezone($requestedTimezone);
        $idempotencyKey = trim($idempotencyKey);

        if ($idempotencyKey === '' || mb_strlen($idempotencyKey) > 128) {
            throw ValidationException::withMessages(['submission_key' => 'The B2B submission key is invalid.']);
        }

        $manualMeetingUrl = $this->manualUrl($meetingMode, $manualMeetingUrl);
        $requestedStart = CarbonImmutable::instance($startsAt)->utc();

        if ($requestedStart->second !== 0 || $requestedStart->microsecond !== 0) {
            throw ValidationException::withMessages(['starts_at' => 'The sales-call time must use whole minutes.']);
        }

        $requestHash = $this->requestHash(
            clientId: (int) $client->getKey(),
            specialistId: (int) $specialist->getKey(),
            startsAt: $requestedStart,
            requestedTimezone: $requestedTimezone,
            meetingMode: $meetingMode,
            manualMeetingUrl: $manualMeetingUrl,
        );

        try {
            return DB::transaction(function () use (
                $actor,
                $client,
                $specialist,
                $requestedStart,
                $requestedTimezone,
                $idempotencyKey,
                $requestHash,
                $source,
                $meetingMode,
                $manualMeetingUrl,
                $organization,
            ): B2bLead {
                $lockedClient = Client::query()
                    ->where('organization_id', $organization->getKey())
                    ->whereKey($client->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();
                $existingLead = B2bLead::query()
                    ->where('organization_id', $organization->getKey())
                    ->where('idempotency_key', $idempotencyKey)
                    ->lockForUpdate()
                    ->first();

                if ($existingLead instanceof B2bLead) {
                    if ($existingLead->request_hash !== $requestHash) {
                        throw ValidationException::withMessages([
                            'submission_key' => 'The B2B submission key was already used for another request.',
                        ]);
                    }

                    if ($existingLead->salesCall()->exists()) {
                        return $existingLead->refresh();
                    }
                }

                if ($this->specialistAnswer->handle($lockedClient) !== B2bSpecialistAnswer::Yes) {
                    throw ValidationException::withMessages([
                        'b2b_specialist_answer' => 'Confirm that you are a massage or bodywork specialist before submitting a B2B request.',
                    ]);
                }

                B2bLead::query()->insertOrIgnore([
                    'organization_id' => $organization->getKey(),
                    'client_id' => $client->getKey(),
                    'b2b_specialist_answer' => B2bSpecialistAnswer::Yes->value,
                    'source_channel' => $source->value,
                    'idempotency_key' => $idempotencyKey,
                    'request_hash' => $requestHash,
                    'status' => B2bLeadStatus::New->value,
                    'event_version' => 1,
                    'submitted_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $lead = B2bLead::query()
                    ->where('organization_id', $organization->getKey())
                    ->where('idempotency_key', $idempotencyKey)
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($lead->request_hash !== $requestHash) {
                    throw ValidationException::withMessages([
                        'submission_key' => 'The B2B submission key was already used for another request.',
                    ]);
                }

                if ($lead->salesCall()->exists()) {
                    return $lead->refresh();
                }

                $durationMinutes = $this->duration->handle();

                if ($durationMinutes === null) {
                    throw ValidationException::withMessages([
                        'configuration' => 'B2B sales-call availability is not configured yet. Contact the team.',
                    ]);
                }

                $requestedEnd = $requestedStart->addMinutes($durationMinutes);

                $lockedSpecialist = Specialist::query()
                    ->where('organization_id', $organization->getKey())
                    ->whereKey($specialist->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();
                $scheduleTimezone = $this->availability->handle(
                    specialist: $lockedSpecialist,
                    startsAt: $requestedStart,
                    endsAt: $requestedEnd,
                );
                $call = new B2bSalesCall;
                $call->forceFill([
                    'organization_id' => $organization->getKey(),
                    'lead_id' => $lead->getKey(),
                    'client_id' => $lockedClient->getKey(),
                    'specialist_id' => $lockedSpecialist->getKey(),
                    'status' => B2bSalesCallStatus::Scheduled,
                    'starts_at' => $requestedStart,
                    'ends_at' => $requestedEnd,
                    'schedule_timezone' => $scheduleTimezone,
                    'requested_timezone' => $requestedTimezone,
                    'meeting_mode' => $meetingMode,
                    'provider_name' => $meetingMode === VideoMeetingMode::Automatic ? 'zoom' : null,
                    'provider_sync_status' => $meetingMode === VideoMeetingMode::Automatic
                        ? VideoMeetingSyncStatus::Pending
                        : VideoMeetingSyncStatus::NotRequired,
                    'provider_operation' => $meetingMode === VideoMeetingMode::Automatic
                        ? VideoMeetingOperation::Create
                        : null,
                    'provider_correlation_key' => $meetingMode === VideoMeetingMode::Automatic
                        ? bin2hex(random_bytes(16))
                        : null,
                    'provider_lease_token' => null,
                    'provider_lease_expires_at' => null,
                    'provider_lease_event_id' => null,
                    'provider_lease_processing_token' => null,
                    'provider_sync_version' => 1,
                    'event_version' => 1,
                ]);

                try {
                    $call->save();
                    $occupancy = new UnavailablePeriod;
                    $occupancy->forceFill([
                        'organization_id' => $organization->getKey(),
                        'specialist_id' => $lockedSpecialist->getKey(),
                        'created_by_user_id' => $actor instanceof User ? $actor->getKey() : null,
                        'b2b_sales_call_id' => $call->getKey(),
                        'starts_at' => $requestedStart,
                        'ends_at' => $requestedEnd,
                    ]);
                    $occupancy->save();
                } catch (QueryException $exception) {
                    if ($this->isScheduleConflict($exception)) {
                        throw ValidationException::withMessages([
                            'starts_at' => 'The selected sales-call time was taken concurrently.',
                        ]);
                    }

                    throw $exception;
                }

                $lead->forceFill([
                    'status' => B2bLeadStatus::ZoomScheduled,
                    'event_version' => $lead->event_version + 1,
                ])->save();
                $this->audit->handle(
                    organization: $organization,
                    actor: $actor instanceof User ? $actor : null,
                    action: 'b2b.lead.submitted',
                    targetType: B2bLead::class,
                    targetId: (string) $lead->getKey(),
                    metadata: [
                        'source' => $source->value,
                        'status' => $lead->status->value,
                        'has_sales_call' => true,
                    ],
                );
                $this->scenarioEvents->b2bLeadSubmitted($lead, $call, CarbonImmutable::now('UTC'));

                if ($meetingMode === VideoMeetingMode::Automatic) {
                    $this->providerEvents->handle($organization, $call, VideoMeetingOperation::Create);
                } elseif ($manualMeetingUrl !== null) {
                    $this->scenarioEvents->b2bSalesCallReady($call, CarbonImmutable::now('UTC'));
                }

                $this->audit->handle(
                    organization: $organization,
                    actor: $actor instanceof User ? $actor : null,
                    action: 'b2b.sales_call.created',
                    targetType: B2bSalesCall::class,
                    targetId: (string) $call->getKey(),
                    metadata: [
                        'source' => $source->value,
                        'status' => B2bSalesCallStatus::Scheduled->value,
                        'meeting_mode' => $meetingMode->value,
                        'provider_sync_status' => $call->provider_sync_status->value,
                    ],
                );

                return $lead->refresh();
            });
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

    private function manualUrl(VideoMeetingMode $mode, ?string $url): ?string
    {
        $url = $url === null ? null : trim($url);

        if ($mode === VideoMeetingMode::Automatic && $url !== null) {
            throw ValidationException::withMessages(['manual_meeting_url' => 'A manual meeting link is only valid in manual mode.']);
        }

        if ($url !== null && (mb_strlen($url) > 2000 || filter_var($url, FILTER_VALIDATE_URL) === false || ! str_starts_with($url, 'https://'))) {
            throw ValidationException::withMessages(['manual_meeting_url' => 'The manual meeting link must be an HTTPS URL.']);
        }

        return $url;
    }

    private function requestHash(
        int $clientId,
        int $specialistId,
        CarbonImmutable $startsAt,
        string $requestedTimezone,
        VideoMeetingMode $meetingMode,
        ?string $manualMeetingUrl,
    ): string {
        return hash('sha256', json_encode([
            'client_id' => $clientId,
            'specialist_id' => $specialistId,
            'starts_at' => $startsAt->toIso8601String(),
            'requested_timezone' => $requestedTimezone,
            'meeting_mode' => $meetingMode->value,
            'manual_meeting_url' => $manualMeetingUrl,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    private function isScheduleConflict(QueryException $exception): bool
    {
        $sqlState = $exception->getCode() ?: ($exception->errorInfo[0] ?? null);

        return in_array($sqlState, ['23P01', '40P01'], true);
    }
}
