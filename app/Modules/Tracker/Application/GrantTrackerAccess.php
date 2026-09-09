<?php

namespace App\Modules\Tracker\Application;

use App\Models\User;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use App\Modules\Security\Application\RecordAuditEvent;
use App\Modules\Tracker\Domain\Enums\TrackerEntitlementSource;
use App\Modules\Tracker\Domain\Models\TrackerEntitlement;
use App\Modules\Tracker\Domain\Models\TrackerPlan;
use App\Modules\Tracker\Domain\Models\TrackerPlanVersion;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class GrantTrackerAccess
{
    public function __construct(
        private readonly OrganizationContext $context,
        private readonly OrganizationAuthorizer $authorizer,
        private readonly RecordAuditEvent $audit,
    ) {}

    public function handle(
        User $actor,
        Client $client,
        ?TrackerPlan $plan,
        CarbonImmutable $startsAt,
        CarbonImmutable $endsAt,
        string $reason,
    ): TrackerEntitlement {
        $organization = $this->context->organization();
        $this->authorizer->authorize($actor, $organization, OrganizationPermission::ManageClients);
        if ((int) $client->organization_id !== (int) $organization->getKey()) {
            throw new AuthorizationException('The client is outside the current organization.');
        }
        if ($endsAt->lessThanOrEqualTo($startsAt) || trim($reason) === '') {
            throw ValidationException::withMessages(['reason' => 'Укажите причину и корректный период доступа.']);
        }

        return DB::transaction(function () use ($actor, $client, $plan, $startsAt, $endsAt, $reason, $organization): TrackerEntitlement {
            $client = Client::query()
                ->where('organization_id', $organization->getKey())
                ->whereKey($client->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            $version = null;
            if ($plan instanceof TrackerPlan) {
                $scopedPlan = TrackerPlan::query()
                    ->where('organization_id', $organization->getKey())
                    ->whereKey($plan->getKey())
                    ->with('currentVersion')
                    ->firstOrFail();
                $version = $scopedPlan->currentVersion;
                if (! $version instanceof TrackerPlanVersion || ! $scopedPlan->is_active || ! $version->included_access) {
                    throw ValidationException::withMessages(['plan' => 'Выберите активный тариф.']);
                }
                $plan = $scopedPlan;
            }

            $active = TrackerEntitlement::query()
                ->where('organization_id', $organization->getKey())
                ->where('client_id', $client->getKey())
                ->where('active', true)
                ->lockForUpdate()
                ->first();
            if ($active instanceof TrackerEntitlement && (int) ($plan?->getKey() ?? 0) === (int) ($active->tracker_plan_id ?? 0)) {
                $active->forceFill([
                    'ends_at' => CarbonImmutable::parse((string) $active->getRawOriginal('ends_at'))->greaterThan($endsAt) ? CarbonImmutable::parse((string) $active->getRawOriginal('ends_at')) : $endsAt,
                    'reason' => trim($reason),
                ])->save();
                $this->audit->handle($organization, $actor, 'tracker.access.granted', TrackerEntitlement::class, (string) $active->getKey(), ['client_id' => $client->getKey(), 'extended_existing' => true]);

                return $active->refresh();
            }
            if ($active instanceof TrackerEntitlement) {
                $active->forceFill(['active' => false, 'ended_at' => CarbonImmutable::now('UTC')])->save();
            }

            $entitlement = new TrackerEntitlement;
            $entitlement->forceFill([
                'organization_id' => $organization->getKey(),
                'client_id' => $client->getKey(),
                'tracker_plan_id' => $plan?->getKey(),
                'tracker_plan_version_id' => $version?->getKey(),
                'active' => true,
                'starts_at' => $startsAt->utc(),
                'ends_at' => $endsAt->utc(),
                'source' => TrackerEntitlementSource::Manual,
                'reason' => trim($reason),
                'applied_plan_name' => $plan?->name,
                'applied_price_minor' => $version?->price_minor,
                'applied_currency' => $version?->currencyCode()->value,
                'applied_duration_days' => $version?->duration_days,
                'created_by_user_id' => $actor->getKey(),
            ])->save();
            $this->audit->handle($organization, $actor, 'tracker.access.granted', TrackerEntitlement::class, (string) $entitlement->getKey(), ['client_id' => $client->getKey(), 'plan_id' => $plan?->getKey()]);

            return $entitlement->refresh();
        });
    }
}
