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
use App\Modules\Scenarios\Application\RecordScenarioEvent;
use App\Modules\Security\Application\RecordAuditEvent;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class SetB2bSalesCallMeetingMode
{
    public function __construct(
        private readonly OrganizationContext $context,
        private readonly OrganizationAuthorizer $authorizer,
        private readonly B2bProviderMutationGuard $providerMutationGuard,
        private readonly RecordB2bProviderSyncEvent $providerEvents,
        private readonly RecordScenarioEvent $scenarioEvents,
        private readonly RecordAuditEvent $audit,
    ) {}

    public function handle(
        User $actor,
        B2bSalesCall $salesCall,
        VideoMeetingMode $mode,
        ?string $manualMeetingUrl = null,
        ?int $expectedEventVersion = null,
    ): B2bSalesCall {
        $organization = $this->context->organization();
        $this->authorizer->authorize($actor, $organization, OrganizationPermission::ManageB2bLeads);

        if ((int) $salesCall->organization_id !== $organization->getKey()) {
            throw new AuthorizationException('The sales call is outside the current organization.');
        }

        $manualMeetingUrl = $this->manualUrl($mode, $manualMeetingUrl);

        $providerChangeBlocked = false;
        $result = DB::transaction(function () use ($actor, $salesCall, $mode, $manualMeetingUrl, $expectedEventVersion, $organization, &$providerChangeBlocked): B2bSalesCall {
            $locked = B2bSalesCall::query()
                ->where('organization_id', $organization->getKey())
                ->whereKey($salesCall->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            if ($expectedEventVersion === null || (int) $locked->event_version !== $expectedEventVersion) {
                throw ValidationException::withMessages([
                    'expected_event_version' => 'This sales call changed before its meeting mode was updated. Refresh and try again.',
                ]);
            }
            if ($locked->status !== B2bSalesCallStatus::Scheduled) {
                throw ValidationException::withMessages(['sales_call' => 'A cancelled sales call cannot change meeting mode.']);
            }

            $identityExists = $locked->providerIdentity() !== null
                || (is_string($locked->provider_recreate_meeting_id)
                    && trim($locked->provider_recreate_meeting_id) !== '');
            $requiresProviderCancellation = $identityExists
                || ($locked->meeting_mode === VideoMeetingMode::Automatic
                    && ($locked->provider_operation !== null
                        || $locked->provider_sync_status !== VideoMeetingSyncStatus::NotRequired));
            $changed = $locked->meeting_mode !== $mode || $locked->manual_meeting_url !== $manualMeetingUrl;
            if (! $changed) {
                return $locked->refresh();
            }
            if (! $this->providerMutationGuard->allowGenerationChange($locked, $actor)) {
                $providerChangeBlocked = true;

                return $locked->refresh();
            }

            $operation = null;
            $syncStatus = VideoMeetingSyncStatus::NotRequired;
            if ($mode === VideoMeetingMode::Automatic) {
                if ($locked->meeting_mode === VideoMeetingMode::Manual
                    && $locked->provider_sync_status !== VideoMeetingSyncStatus::NotRequired) {
                    throw ValidationException::withMessages([
                        'provider' => 'Finish the previous provider reconciliation before enabling automatic meetings.',
                    ]);
                }
                $operation = $identityExists && $locked->provider_join_url !== null
                    ? VideoMeetingOperation::Update
                    : ($identityExists ? VideoMeetingOperation::Recreate : VideoMeetingOperation::Create);
                $syncStatus = $locked->provider_sync_status === VideoMeetingSyncStatus::ReconciliationRequired
                    ? VideoMeetingSyncStatus::ReconciliationRequired
                    : VideoMeetingSyncStatus::Pending;
            } elseif ($requiresProviderCancellation) {
                $operation = VideoMeetingOperation::Cancel;
                $syncStatus = $locked->provider_sync_status === VideoMeetingSyncStatus::ReconciliationRequired
                    ? VideoMeetingSyncStatus::ReconciliationRequired
                    : VideoMeetingSyncStatus::CancellationPending;
            }
            $providerCorrelationKey = $locked->provider_correlation_key;
            if ($mode === VideoMeetingMode::Automatic
                && ($locked->meeting_mode === VideoMeetingMode::Manual || $operation === VideoMeetingOperation::Recreate)
                && $locked->provider_sync_status !== VideoMeetingSyncStatus::ReconciliationRequired) {
                $providerCorrelationKey = bin2hex(random_bytes(16));
            }
            if ($mode === VideoMeetingMode::Manual && ! $requiresProviderCancellation) {
                $providerCorrelationKey = null;
            }

            $locked->forceFill([
                'meeting_mode' => $mode,
                'manual_meeting_url' => $manualMeetingUrl,
                'provider_name' => $mode === VideoMeetingMode::Automatic || $requiresProviderCancellation ? 'zoom' : null,
                'provider_sync_status' => $syncStatus,
                'provider_operation' => $operation,
                'provider_recreate_meeting_id' => match ($operation) {
                    VideoMeetingOperation::Recreate => $locked->provider_meeting_id,
                    VideoMeetingOperation::Cancel => $locked->provider_recreate_meeting_id,
                    default => null,
                },
                'provider_recreate_correlation_key' => match ($operation) {
                    VideoMeetingOperation::Recreate => $locked->provider_correlation_key,
                    VideoMeetingOperation::Cancel => $locked->provider_recreate_correlation_key,
                    default => null,
                },
                'provider_correlation_key' => $providerCorrelationKey,
                'provider_sync_version' => (int) $locked->provider_sync_version + 1,
                'event_version' => (int) $locked->event_version + 1,
                'provider_error_code' => null,
                'provider_join_url' => $mode === VideoMeetingMode::Automatic ? $locked->provider_join_url : null,
                'provider_lease_token' => null,
                'provider_lease_expires_at' => null,
                'provider_lease_event_id' => null,
                'provider_lease_processing_token' => null,
            ])->save();

            if ($operation instanceof VideoMeetingOperation) {
                $this->providerEvents->handle($organization, $locked, $operation);
            }

            if ($mode === VideoMeetingMode::Manual && $manualMeetingUrl !== null) {
                $this->scenarioEvents->b2bSalesCallReady($locked, now()->toImmutable());
            }

            $this->audit->handle(
                organization: $organization,
                actor: $actor,
                action: 'b2b.sales_call.manual_link.updated',
                targetType: B2bSalesCall::class,
                targetId: (string) $locked->getKey(),
                metadata: [
                    'source' => 'crm',
                    'meeting_mode' => $mode->value,
                    'url_set' => $manualMeetingUrl !== null,
                ],
            );

            return $locked->refresh();
        });

        if ($providerChangeBlocked) {
            throw ValidationException::withMessages(['provider' => B2bProviderMutationGuard::LOST_MESSAGE]);
        }

        return $result;
    }

    private function manualUrl(VideoMeetingMode $mode, ?string $url): ?string
    {
        $url = $url === null ? null : trim($url);
        if ($mode === VideoMeetingMode::Automatic && $url !== null) {
            throw ValidationException::withMessages(['manual_meeting_url' => 'A manual meeting link is only valid in manual mode.']);
        }
        if ($mode === VideoMeetingMode::Manual && ($url === null || $url === '')) {
            throw ValidationException::withMessages(['manual_meeting_url' => 'Для ручной ссылки укажите HTTPS-ссылку клиента.']);
        }
        $parts = $url === null ? null : parse_url($url);
        if ($url !== null && (mb_strlen($url) > 2000
            || filter_var($url, FILTER_VALIDATE_URL) === false
            || ! is_array($parts)
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || ! is_string($parts['host'] ?? null)
            || trim((string) $parts['host']) === ''
            || array_key_exists('user', $parts)
            || array_key_exists('pass', $parts))) {
            throw ValidationException::withMessages(['manual_meeting_url' => 'The manual meeting link must be an HTTPS URL.']);
        }

        return $url;
    }
}
