<?php

namespace App\Modules\AI\Application\Actions;

use App\Models\User;
use App\Modules\AI\Domain\Models\AiModelConfiguration;
use App\Modules\AI\Domain\Models\AiProviderConfiguration;
use App\Modules\AI\Domain\ValueObjects\AiPricingSnapshot;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use App\Modules\Security\Application\RecordAuditEvent;
use Illuminate\Auth\Access\AuthorizationException;
use InvalidArgumentException;

final class CreateModelConfiguration
{
    public function __construct(
        private readonly OrganizationAuthorizer $authorizer,
        private readonly RecordAuditEvent $audit,
    ) {}

    /** @param array<string, mixed> $data */
    public function handle(User $actor, AiProviderConfiguration $provider, array $data): AiModelConfiguration
    {
        $organization = $provider->organization;
        $this->authorizer->authorize($actor, $organization, OrganizationPermission::ManageAiProviders);

        if ((int) $provider->organization_id !== (int) $organization->getKey()) {
            throw new AuthorizationException('Provider configuration is outside the current organization.');
        }

        $modelName = trim((string) ($data['model_name'] ?? ''));
        $displayName = trim((string) ($data['display_name'] ?? ''));
        if ($modelName === '' || $displayName === '') {
            throw new InvalidArgumentException('Model name and display name are required.');
        }

        $pricing = new AiPricingSnapshot(
            currency: 'USD',
            inputCostPerMillionMinorUnits: max(0, (int) ($data['input_cost_per_million'] ?? 0)),
            outputCostPerMillionMinorUnits: max(0, (int) ($data['output_cost_per_million'] ?? 0)),
        );

        $model = AiModelConfiguration::create([
            'organization_id' => $organization->getKey(),
            'provider_config_id' => $provider->getKey(),
            'model_name' => $modelName,
            'display_name' => $displayName,
            'is_enabled' => false,
            'lifecycle_status' => 'preview',
            'capabilities' => array_values(array_map('strval', (array) ($data['capabilities'] ?? []))),
            'pricing_snapshot' => $pricing->toArray(),
            'failover_priority' => max(1, (int) ($data['failover_priority'] ?? 1)),
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
