<?php

namespace App\Modules\Tracker\Application;

use App\Models\User;
use App\Modules\Commerce\Domain\Models\PaymentProviderOfferMapping;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Security\Application\RecordAuditEvent;
use App\Modules\Tracker\Domain\Models\TrackerPlan;
use App\Modules\Tracker\Domain\Models\TrackerPlanVersion;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class MoveTrackerPlan
{
    public function __construct(
        private readonly OrganizationContext $context,
        private readonly OrganizationAuthorizer $authorizer,
        private readonly RecordAuditEvent $audit,
    ) {}

    public function handle(User $actor, TrackerPlan $plan, string $direction): TrackerPlan
    {
        $organization = $this->context->organization();
        $this->authorizer->authorize($actor, $organization, OrganizationPermission::ManageSettings);

        if (! in_array($direction, ['up', 'down'], true)) {
            throw ValidationException::withMessages(['direction' => 'Выберите направление перемещения.']);
        }

        return DB::transaction(function () use ($actor, $organization, $plan, $direction): TrackerPlan {
            $plans = TrackerPlan::query()
                ->where('organization_id', $organization->getKey())
                ->with('currentVersion')
                ->lockForUpdate()
                ->get()
                ->filter(static fn (TrackerPlan $candidate): bool => $candidate->currentVersion instanceof TrackerPlanVersion)
                ->sort(static function (TrackerPlan $left, TrackerPlan $right): int {
                    $leftVersion = self::currentVersionForOrdering($left);
                    $rightVersion = self::currentVersionForOrdering($right);
                    $order = ((int) $leftVersion->display_order) <=> ((int) $rightVersion->display_order);
                    if ($order !== 0) {
                        return $order;
                    }

                    $name = strcasecmp((string) $left->name, (string) $right->name);

                    return $name !== 0 ? $name : ((int) $left->getKey() <=> (int) $right->getKey());
                })
                ->values();

            $currentIndex = $plans->search(fn (TrackerPlan $candidate): bool => (int) $candidate->getKey() === (int) $plan->getKey());
            if ($currentIndex === false) {
                throw ValidationException::withMessages(['plan' => 'Выбранный тариф недоступен в этой организации.']);
            }

            $neighborIndex = $direction === 'up' ? $currentIndex - 1 : $currentIndex + 1;
            if (! $plans->has($neighborIndex)) {
                throw ValidationException::withMessages([
                    'plan' => $direction === 'up'
                        ? 'Тариф уже находится в начале списка.'
                        : 'Тариф уже находится в конце списка.',
                ]);
            }

            $ordered = $plans->all();
            [$ordered[$currentIndex], $ordered[$neighborIndex]] = [$ordered[$neighborIndex], $ordered[$currentIndex]];

            foreach ($ordered as $index => $candidate) {
                $version = $candidate->currentVersion;
                if (! $version instanceof TrackerPlanVersion || (int) $version->display_order === $index) {
                    continue;
                }

                $newVersion = $this->copyVersion($actor, $organization, $candidate, $version, $index);
                $candidate->forceFill(['current_version_id' => $newVersion->getKey()])->save();
                $this->copyActiveMappings($organization, $version, $newVersion);
                $this->audit->handle(
                    organization: $organization,
                    actor: $actor,
                    action: 'tracker.plan.reordered',
                    targetType: TrackerPlanVersion::class,
                    targetId: (string) $newVersion->getKey(),
                    metadata: [
                        'plan_id' => $candidate->getKey(),
                        'from_order' => $version->display_order,
                        'to_order' => $index,
                    ],
                );
            }

            $selectedPlan = $plans->get($currentIndex);
            if (! $selectedPlan instanceof TrackerPlan) {
                throw ValidationException::withMessages(['plan' => 'Выбранный тариф недоступен в этой организации.']);
            }

            return $selectedPlan->refresh();
        }, attempts: 3);
    }

    private static function currentVersionForOrdering(TrackerPlan $plan): TrackerPlanVersion
    {
        $version = $plan->currentVersion;
        if (! $version instanceof TrackerPlanVersion) {
            throw ValidationException::withMessages(['plan' => 'Выбранный тариф недоступен в этой организации.']);
        }

        return $version;
    }

    private function copyVersion(
        User $actor,
        Organization $organization,
        TrackerPlan $plan,
        TrackerPlanVersion $source,
        int $displayOrder,
    ): TrackerPlanVersion {
        $nextVersion = ((int) TrackerPlanVersion::query()
            ->where('tracker_plan_id', $plan->getKey())
            ->lockForUpdate()
            ->pluck('version')
            ->max()) + 1;
        $version = new TrackerPlanVersion;
        $version->forceFill([
            'organization_id' => $organization->getKey(),
            'tracker_plan_id' => $plan->getKey(),
            'version' => $nextVersion,
            'price_minor' => $source->price_minor,
            'currency' => $source->getRawOriginal('currency'),
            'duration_days' => $source->duration_days,
            'description' => $source->description,
            'monthly_practice' => $source->monthly_practice,
            'included_access' => $source->included_access,
            'display_order' => $displayOrder,
            'created_by_user_id' => $actor->getKey(),
            'created_at' => CarbonImmutable::now('UTC'),
        ])->save();

        return $version->refresh();
    }

    private function copyActiveMappings(
        Organization $organization,
        TrackerPlanVersion $source,
        TrackerPlanVersion $target,
    ): void {
        PaymentProviderOfferMapping::query()
            ->where('organization_id', $organization->getKey())
            ->where('sellable_type', TrackerPlanVersion::class)
            ->where('sellable_id', $source->getKey())
            ->where('is_active', true)
            ->get()
            ->each(function (PaymentProviderOfferMapping $mapping) use ($organization, $target): void {
                $copy = new PaymentProviderOfferMapping;
                $copy->forceFill([
                    'organization_id' => $organization->getKey(),
                    'gateway' => $mapping->gateway,
                    'sellable_type' => TrackerPlanVersion::class,
                    'sellable_id' => $target->getKey(),
                    'currency' => $mapping->getRawOriginal('currency'),
                    'external_offer_id' => $mapping->external_offer_id,
                    'provider_metadata' => $mapping->provider_metadata,
                    'is_active' => true,
                ])->save();
            });
    }
}
