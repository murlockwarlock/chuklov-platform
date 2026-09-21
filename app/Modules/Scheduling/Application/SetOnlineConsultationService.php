<?php

namespace App\Modules\Scheduling\Application;

use App\Models\User;
use App\Modules\Organizations\Application\ClearOrganizationSetting;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Application\SetOrganizationSetting;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use App\Modules\Organizations\Domain\Enums\OrganizationSettingKey;
use App\Modules\Services\Domain\Models\Service;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

final class SetOnlineConsultationService
{
    public function __construct(
        private readonly OrganizationContext $context,
        private readonly OrganizationAuthorizer $authorizer,
        private readonly SetOrganizationSetting $settings,
        private readonly ClearOrganizationSetting $clearSetting,
        private readonly ResolveOnlineConsultationService $resolver,
    ) {}

    public function handle(User $actor, ?int $serviceId): void
    {
        $organization = $this->context->organization();
        $this->authorizer->authorize($actor, $organization, OrganizationPermission::ManageScheduling);

        if ($serviceId === null) {
            $this->clearSetting->handle($actor, OrganizationSettingKey::OnlineConsultationServiceId);

            return;
        }

        $service = Service::query()
            ->where('organization_id', $organization->getKey())
            ->whereKey($serviceId)
            ->first();
        if (! $service instanceof Service) {
            throw new AuthorizationException('The consultation service is outside the current organization.');
        }

        if (! $this->resolver->eligible($service, (int) $organization->getKey())) {
            throw ValidationException::withMessages([
                'online_consultation_service_id' => 'Выберите активную онлайн-услугу с полной предоплатой, ценой и назначенным специалистом.',
            ]);
        }

        $this->settings->handle($actor, OrganizationSettingKey::OnlineConsultationServiceId, $serviceId);
    }
}
