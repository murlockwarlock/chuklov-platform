<?php

namespace App\Modules\Scheduling\Application;

use App\Modules\Organizations\Domain\Enums\OrganizationSettingKey;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Organizations\Domain\Models\OrganizationSetting;
use App\Modules\Scheduling\Domain\Models\SpecialistServiceAssignment;
use App\Modules\Services\Domain\Enums\CatalogItemType;
use App\Modules\Services\Domain\Enums\ServicePaymentRequirement;
use App\Modules\Services\Domain\Models\Service;

final class ResolveOnlineConsultationService
{
    public function handle(Organization|int $organization): ?Service
    {
        $organizationId = $organization instanceof Organization ? (int) $organization->getKey() : $organization;
        $serviceId = OrganizationSetting::query()
            ->where('organization_id', $organizationId)
            ->where('setting_key', OrganizationSettingKey::OnlineConsultationServiceId->value)
            ->value('integer_value');

        if ($serviceId === null) {
            return null;
        }

        $service = Service::query()
            ->where('organization_id', $organizationId)
            ->whereKey((int) $serviceId)
            ->first();

        return $service instanceof Service && $this->eligible($service, $organizationId) ? $service : null;
    }

    public function eligible(Service $service, int $organizationId): bool
    {
        if ((int) $service->organization_id !== $organizationId
            || ! $service->is_active
            || $service->catalogItemType() !== CatalogItemType::Service
            || $service->payment_requirement !== ServicePaymentRequirement::PrepayFull
            || $service->durationMinutes() === null
            || $service->price_minor === null
            || $service->price_minor <= 0
            || ! is_string($service->price_currency)
            || ! in_array('online', $service->supportedFormats(), true)) {
            return false;
        }

        return SpecialistServiceAssignment::query()
            ->where('organization_id', $organizationId)
            ->where('service_id', $service->getKey())
            ->whereHas('specialist', fn ($query) => $query
                ->where('organization_id', $organizationId)
                ->where('is_active', true))
            ->exists();
    }
}
