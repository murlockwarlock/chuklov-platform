<?php

namespace App\Modules\Tracker\Application;

use App\Models\User;
use App\Modules\Finance\Application\CurrencyConfigurationService;
use App\Modules\Finance\Domain\Services\CurrencyCatalog;
use App\Modules\Finance\Domain\ValueObjects\Money;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use App\Modules\Security\Application\RecordAuditEvent;
use App\Modules\Tracker\Domain\Models\TrackerPlan;
use App\Modules\Tracker\Domain\Models\TrackerPlanVersion;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class SaveTrackerPlan
{
    public function __construct(
        private readonly OrganizationContext $context,
        private readonly OrganizationAuthorizer $authorizer,
        private readonly CurrencyCatalog $catalog,
        private readonly CurrencyConfigurationService $currencies,
        private readonly RecordAuditEvent $audit,
    ) {}

    public function handle(
        User $actor,
        ?TrackerPlan $plan,
        string $name,
        bool $active,
        bool $visible,
        string $price,
        string $currency,
        int $durationDays,
        ?string $description,
        bool $includedAccess,
        int $displayOrder,
    ): TrackerPlanVersion {
        $organization = $this->context->organization();
        $this->authorizer->authorize($actor, $organization, OrganizationPermission::ManageSettings);
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 160 || $durationDays < 1 || $displayOrder < 0) {
            throw ValidationException::withMessages(['name' => 'Проверьте название, срок и порядок тарифа.']);
        }

        try {
            $code = $this->catalog->code($currency);
            $allowed = $this->currencies->allowedCurrencies($organization->getKey());
            if ($allowed !== [] && ! in_array($code, $allowed, true)) {
                throw new \InvalidArgumentException;
            }
            $money = Money::fromDecimal($price, $code);
            if ($money->isNegative()) {
                throw new \InvalidArgumentException;
            }
        } catch (\Throwable) {
            throw ValidationException::withMessages(['price' => 'Укажите цену и допустимую валюту.']);
        }

        return DB::transaction(function () use ($actor, $organization, $plan, $name, $active, $visible, $money, $code, $durationDays, $description, $includedAccess, $displayOrder): TrackerPlanVersion {
            $locked = $plan instanceof TrackerPlan
                ? TrackerPlan::query()->where('organization_id', $organization->getKey())->whereKey($plan->getKey())->lockForUpdate()->first()
                : null;
            if ($plan instanceof TrackerPlan && ! $locked instanceof TrackerPlan) {
                throw (new ModelNotFoundException)->setModel(TrackerPlan::class);
            }
            $locked ??= new TrackerPlan;
            $locked->forceFill([
                'organization_id' => $organization->getKey(),
                'name' => $name,
                'is_active' => $active,
                'is_visible' => $visible,
                'archived_at' => $active ? null : ($locked->archived_at ?? CarbonImmutable::now('UTC')),
            ])->save();
            $versionNumber = ((int) $locked->versions()->lockForUpdate()->max('version')) + 1;
            $version = new TrackerPlanVersion;
            $version->forceFill([
                'organization_id' => $organization->getKey(),
                'tracker_plan_id' => $locked->getKey(),
                'version' => $versionNumber,
                'price_minor' => $money->minorUnits(),
                'currency' => $code->value,
                'duration_days' => $durationDays,
                'description' => $description === null ? null : trim($description),
                'included_access' => $includedAccess,
                'display_order' => $displayOrder,
                'created_by_user_id' => $actor->getKey(),
                'created_at' => CarbonImmutable::now('UTC'),
            ])->save();
            $locked->forceFill(['current_version_id' => $version->getKey()])->save();
            $this->audit->handle(
                organization: $organization,
                actor: $actor,
                action: 'tracker.plan.version.saved',
                targetType: TrackerPlanVersion::class,
                targetId: (string) $version->getKey(),
                metadata: ['plan_id' => $locked->getKey(), 'version' => $versionNumber],
            );

            return $version->refresh();
        });
    }
}
