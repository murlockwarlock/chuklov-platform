<?php

namespace App\Modules\Services\Application;

use App\Models\User;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Application\OrganizationFeatureGate;
use App\Modules\Organizations\Domain\Enums\OrganizationFeature;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use App\Modules\Security\Application\RecordAuditEvent;
use App\Modules\Services\Domain\Models\Service;
use App\Modules\Services\Domain\ValueObjects\ServiceConfiguration;
use Illuminate\Support\Facades\DB;

class CreateService
{
    public function __construct(
        private readonly OrganizationContext $context,
        private readonly OrganizationAuthorizer $authorizer,
        private readonly OrganizationFeatureGate $features,
        private readonly RecordAuditEvent $audit,
    ) {}

    /**
     * @param  string|array<string, mixed>  $name
     * @param  list<string>  $formats
     */
    public function handle(
        User $actor,
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
    ): Service {
        $organization = $this->context->organization();
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

        return DB::transaction(function () use ($organization, $actor, $configuration): Service {
            $service = new Service;
            $service->forceFill([
                'organization_id' => $organization->getKey(),
                ...$configuration->attributes(),
            ]);
            $service->save();

            $this->audit->handle(
                organization: $organization,
                actor: $actor,
                action: 'service.created',
                targetType: Service::class,
                targetId: (string) $service->getKey(),
                metadata: [
                    'source' => 'application',
                    'is_active' => $configuration->isActive,
                    'has_price' => $configuration->priceMinor !== null,
                ],
            );

            return $service->refresh();
        });
    }
}
