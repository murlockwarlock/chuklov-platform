<?php

namespace App\Modules\Services\Application;

use App\Models\User;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Application\OrganizationFeatureGate;
use App\Modules\Organizations\Domain\Enums\OrganizationFeature;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use App\Modules\Scheduling\Application\ScheduleMutationImpactCalculator;
use App\Modules\Security\Application\RecordAuditEvent;
use App\Modules\Services\Domain\Models\Service;
use App\Modules\Services\Domain\ValueObjects\ServiceConfiguration;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UpdateService
{
    public function __construct(
        private readonly OrganizationContext $context,
        private readonly OrganizationAuthorizer $authorizer,
        private readonly OrganizationFeatureGate $features,
        private readonly ScheduleMutationImpactCalculator $impactCalculator,
        private readonly RecordAuditEvent $audit,
    ) {}

    /**
     * @param  string|array<string, mixed>  $name
     * @param  list<string>  $formats
     */
    public function handle(
        User $actor,
        Service $service,
        string|array $name,
        string $summary = '',
        bool $isActive = true,
        ?string $nameRu = null,
        ?string $nameEn = null,
        ?string $descriptionRu = null,
        ?string $descriptionEn = null,
        ?string $category = null,
        ?int $durationMinutes = null,
        int $bufferMinutes = 0,
        array $formats = [],
        ?int $priceMinor = null,
        ?string $priceCurrency = null,
        ?string $paymentPolicy = null,
        string $catalogType = 'service',
        bool $acknowledgeImpact = false,
    ): Service {
        $organization = $this->context->organization();

        if ((int) $service->organization_id !== $organization->getKey()) {
            throw new AuthorizationException('The service is outside the current organization.');
        }

        $this->features->authorize($organization, OrganizationFeature::ServiceCatalog);
        $this->authorizer->authorize($actor, $organization, OrganizationPermission::ManageServices);

        $configuration = ServiceConfiguration::from(is_array($name) ? $name : [
            'name' => $name,
            'summary' => $summary,
            'is_active' => $isActive,
            'catalog_type' => $catalogType,
            'name_ru' => $nameRu,
            'name_en' => $nameEn,
            'description_ru' => $descriptionRu,
            'description_en' => $descriptionEn,
            'category' => $category,
            'duration_minutes' => $durationMinutes,
            'buffer_minutes' => $bufferMinutes,
            'formats' => $formats,
            'price_minor' => $priceMinor,
            'price_currency' => $priceCurrency,
            'payment_policy' => $paymentPolicy,
        ]);

        return DB::transaction(function () use ($actor, $configuration, $organization, $service, $acknowledgeImpact): Service {
            $lockedService = Service::query()
                ->where('organization_id', $organization->getKey())
                ->whereKey($service->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            $impact = $this->impactCalculator->forServiceChange($lockedService, $configuration->attributes());

            if ($impact->hasConflicts() && ! $acknowledgeImpact) {
                throw ValidationException::withMessages([
                    'schedule_impact' => $impact->count().' future booking(s) are affected. Review and acknowledge the impact before saving.',
                ]);
            }
            $changedFields = [];

            foreach ($configuration->attributes() as $field => $value) {
                if ($lockedService->getAttribute($field) !== $value) {
                    $changedFields[] = $field;
                }
            }

            $lockedService->forceFill($configuration->attributes());
            $lockedService->save();

            if ($changedFields !== []) {
                $this->audit->handle(
                    organization: $organization,
                    actor: $actor,
                    action: 'service.updated',
                    targetType: Service::class,
                    targetId: (string) $lockedService->getKey(),
                    metadata: [
                        'fields' => implode(',', $changedFields),
                        'is_active' => $configuration->isActive,
                        'has_price' => $configuration->priceMinor !== null,
                    ],
                );
            }

            if ($impact->hasConflicts()) {
                $this->audit->handle(
                    organization: $organization,
                    actor: $actor,
                    action: 'schedule.mutation.acknowledged',
                    targetType: Service::class,
                    targetId: (string) $lockedService->getKey(),
                    metadata: [
                        'source' => 'crm',
                        'mutation' => 'service_change',
                        'affected_booking_count' => $impact->count(),
                    ],
                );
            }

            return $lockedService->refresh();
        });
    }
}
