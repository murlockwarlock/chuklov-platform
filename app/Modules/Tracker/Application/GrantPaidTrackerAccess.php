<?php

namespace App\Modules\Tracker\Application;

use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Security\Application\RecordAuditEvent;
use App\Modules\Tracker\Domain\Enums\TrackerEntitlementSource;
use App\Modules\Tracker\Domain\Models\TrackerEntitlement;
use App\Modules\Tracker\Domain\Models\TrackerPlanVersion;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class GrantPaidTrackerAccess
{
    public function __construct(private readonly RecordAuditEvent $audit) {}

    public function handle(Organization $organization, Client $client, TrackerPlanVersion $version): TrackerEntitlement
    {
        if ((int) $client->organization_id !== (int) $organization->getKey()
            || (int) $version->organization_id !== (int) $organization->getKey()
            || ! $version->included_access
            || $version->duration_days < 1) {
            throw ValidationException::withMessages(['fulfillment' => 'Не удалось подтвердить условия доступа трекера.']);
        }

        return DB::transaction(function () use ($organization, $client, $version): TrackerEntitlement {
            $scopedClient = Client::query()
                ->where('organization_id', $organization->getKey())
                ->whereKey($client->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            $active = TrackerEntitlement::query()
                ->where('organization_id', $organization->getKey())
                ->where('client_id', $scopedClient->getKey())
                ->where('active', true)
                ->lockForUpdate()
                ->first();
            $now = CarbonImmutable::now('UTC');
            $currentEndsAt = $active instanceof TrackerEntitlement
                ? CarbonImmutable::parse((string) $active->getRawOriginal('ends_at'))
                : null;

            if ($active instanceof TrackerEntitlement
                && (int) $active->tracker_plan_id === (int) $version->tracker_plan_id) {
                $base = $currentEndsAt !== null && $currentEndsAt->greaterThan($now) ? $currentEndsAt : $now;
                $active->forceFill([
                    'ends_at' => $base->addDays($version->duration_days),
                    'reason' => 'Оплаченная покупка',
                    'applied_plan_name' => $version->plan()->value('name'),
                    'applied_price_minor' => $version->price_minor,
                    'applied_currency' => $version->currencyCode()->value,
                    'applied_duration_days' => $version->duration_days,
                    'applied_monthly_practice' => $version->monthly_practice,
                ])->save();
                $this->audit($organization, $active, $scopedClient, $version);

                return $active->refresh();
            }

            if ($active instanceof TrackerEntitlement) {
                $active->forceFill([
                    'active' => false,
                    'ended_at' => $now,
                ])->save();
            }

            $entitlement = new TrackerEntitlement;
            $entitlement->forceFill([
                'organization_id' => $organization->getKey(),
                'client_id' => $scopedClient->getKey(),
                'tracker_plan_id' => $version->tracker_plan_id,
                'tracker_plan_version_id' => $version->getKey(),
                'active' => true,
                'starts_at' => $now,
                'ends_at' => $now->addDays($version->duration_days),
                'source' => TrackerEntitlementSource::PaidPurchase->value,
                'reason' => 'Оплаченная покупка',
                'applied_plan_name' => $version->plan()->value('name'),
                'applied_price_minor' => $version->price_minor,
                'applied_currency' => $version->currencyCode()->value,
                'applied_duration_days' => $version->duration_days,
                'applied_monthly_practice' => $version->monthly_practice,
                'created_by_user_id' => null,
            ])->save();
            $this->audit($organization, $entitlement, $scopedClient, $version);

            return $entitlement->refresh();
        });
    }

    private function audit(
        Organization $organization,
        TrackerEntitlement $entitlement,
        Client $client,
        TrackerPlanVersion $version,
    ): void {
        $this->audit->handle(
            organization: $organization,
            actor: null,
            action: 'tracker.access.granted',
            targetType: TrackerEntitlement::class,
            targetId: (string) $entitlement->getKey(),
            metadata: [
                'client_id' => $client->getKey(),
                'plan_id' => $version->tracker_plan_id,
            ],
        );
    }
}
