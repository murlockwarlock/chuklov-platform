<?php

namespace App\Modules\B2B\Application;

use App\Models\User;
use App\Modules\B2B\Domain\Enums\B2bFormConfigurationStatus;
use App\Modules\B2B\Domain\Models\B2bFormConfiguration;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use App\Modules\Security\Application\RecordAuditEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final readonly class PublishB2bFormConfiguration
{
    public function __construct(
        private OrganizationContext $context,
        private OrganizationAuthorizer $authorizer,
        private ValidateB2bFormDefinition $validator,
        private RecordAuditEvent $audit,
    ) {}

    public function handle(User $actor, B2bFormConfiguration $configuration): B2bFormConfiguration
    {
        $organization = $this->context->organization();
        $this->authorizer->authorize($actor, $organization, OrganizationPermission::ManageSettings);
        if ((int) $configuration->organization_id !== (int) $organization->getKey()) {
            throw ValidationException::withMessages(['configuration' => 'Конфигурация принадлежит другой организации.']);
        }
        $fields = $this->validator->handle((array) $configuration->getAttribute('fields'));

        return DB::transaction(function () use ($actor, $organization, $configuration, $fields): B2bFormConfiguration {
            $locked = B2bFormConfiguration::query()->where('organization_id', $organization->getKey())->whereKey($configuration->getKey())->lockForUpdate()->firstOrFail();
            B2bFormConfiguration::query()->where('organization_id', $organization->getKey())->where('status', B2bFormConfigurationStatus::Published->value)->update([
                'status' => B2bFormConfigurationStatus::Retired->value,
                'updated_at' => now(),
            ]);
            $locked->forceFill([
                'fields' => $fields,
                'status' => B2bFormConfigurationStatus::Published,
                'published_at' => now(),
            ])->save();
            $this->audit->handle($organization, $actor, 'b2b.form.published', B2bFormConfiguration::class, (string) $locked->getKey(), [
                'version' => $locked->version,
                'field_count' => count($fields),
            ]);

            return $locked->refresh();
        });
    }
}
