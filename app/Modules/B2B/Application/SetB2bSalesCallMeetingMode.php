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

        return DB::transaction(function () use ($actor, $salesCall, $mode, $manualMeetingUrl, $expectedEventVersion, $organization): B2bSalesCall {
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

            $identityExists = $locked->providerIdentity() !== null;
            $requiresProviderCancellation = $identityExists
                || ($locked->meeting_mode === VideoMeetingMode::Automatic
                    && ($locked->provider_operation !== null
                        || $locked->provider_sync_status !== VideoMeetingSyncStatus::NotRequired));
            $changed = $locked->meeting_mode !== $mode || $locked->manual_meeting_url !== $manualMeetingUrl;
            if (! $changed) {
                return $locked->refresh();
            }

            $operation = null;
            $syncStatus = VideoMeetingSyncStatus::NotRequired;
            if ($mode === VideoMeetingMode::Automatic) {
                $operation = $identityExists && $locked->provider_join_url !== null
                    ? VideoMeetingOperation::Update
                    : ($identityExists ? VideoMeetingOperation::Recreate : VideoMeetingOperation::Create);
                $syncStatus = VideoMeetingSyncStatus::Pending;
            } elseif ($requiresProviderCancellation) {
                $operation = VideoMeetingOperation::Cancel;
                $syncStatus = VideoMeetingSyncStatus::CancellationPending;
            }

            $locked->forceFill([
                'meeting_mode' => $mode,
                'manual_meeting_url' => $manualMeetingUrl,
                'provider_name' => $mode === VideoMeetingMode::Automatic || $requiresProviderCancellation ? 'zoom' : null,
                'provider_sync_status' => $syncStatus,
                'provider_operation' => $operation,
                'provider_recreate_meeting_id' => $operation === VideoMeetingOperation::Recreate
                    ? $locked->provider_meeting_id
                    : null,
                'provider_sync_version' => (int) $locked->provider_sync_version + 1,
                'event_version' => (int) $locked->event_version + 1,
                'provider_error_code' => null,
                'provider_join_url' => $mode === VideoMeetingMode::Automatic ? $locked->provider_join_url : null,
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
}
