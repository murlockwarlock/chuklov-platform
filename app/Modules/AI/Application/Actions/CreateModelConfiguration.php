<?php

namespace App\Modules\AI\Application\Actions;

use App\Models\User;
use App\Modules\AI\Application\Data\AiModelConfigurationInput;
use App\Modules\AI\Domain\Models\AiModelConfiguration;
use App\Modules\AI\Domain\Models\AiProviderConfiguration;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use App\Modules\Security\Application\RecordAuditEvent;
use Illuminate\Auth\Access\AuthorizationException;

final class CreateModelConfiguration
{
    public function __construct(
        private readonly OrganizationContext $context,
        private readonly OrganizationAuthorizer $authorizer,
        private readonly RecordAuditEvent $audit,
    ) {}

    /** @param array<string, mixed> $data */
    public function handle(User $actor, AiProviderConfiguration $provider, array $data): AiModelConfiguration
    {
        $organization = $this->context->organization();
        $this->authorizer->authorize($actor, $organization, OrganizationPermission::ManageAiProviders);

        if ((int) $provider->organization_id !== (int) $organization->getKey()) {
            throw new AuthorizationException('Provider configuration is outside the current organization.');
        }

        $input = AiModelConfigurationInput::forCreate($provider, $data);
        $model = AiModelConfiguration::create([
            'organization_id' => $organization->getKey(),
            'provider_config_id' => $provider->getKey(),
            'model_name' => $input->modelName,
            'display_name' => $input->displayName,
            'is_enabled' => $input->isEnabled,
            'lifecycle_status' => 'preview',
            'capabilities' => $input->capabilities,
            'pricing_snapshot' => $input->pricing->toArray(),
            'failover_priority' => $input->failoverPriority,
        ]);

        $this->audit->handle(
            organization: $organization,
            actor: $actor,
            action: 'ai.model_config.created',
            targetType: AiModelConfiguration::class,
            targetId: (string) $model->id,
            metadata: [
                'model_name' => $model->model_name,
                'is_enabled' => false,
            ],
        );

        return $model;
    }
}
